<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TextSMS provider adapter (https://sms.textsms.co.ke).
 *
 * Default concrete implementation of {@see SmsSender} and
 * {@see SmsDeliveryReportProcessor} shipped with the package. Other
 * providers (Africa's Talking, Twilio, an in-house aggregator) are added
 * by writing a sibling class and rebinding the contracts in the
 * application's service provider — see `docs/extending-providers.md`.
 *
 * Provider quirks worth knowing (preserved verbatim from the QPIMS
 * implementation):
 *
 *  - **Auth in body, not headers.** TextSMS reads `apikey` and
 *    `partnerID` from the JSON body of every request.
 *  - **`respose-code` is misspelled on the wire.** The success key in
 *    `responses[]` is `respose-code` (sic) — `response-code` is also
 *    accepted defensively in case they fix it.
 *  - **`200` is success.** All `1xxx` and `4xxx` codes are documented
 *    errors (e.g. `1003` validation, `1004` low credits, `1006` invalid
 *    credentials).
 *  - **Bulk endpoint caps at 20 per call.** Throws
 *    {@see SmsDispatchException} when exceeded; the caller decides
 *    batching strategy.
 *  - **Three response shapes** for `send`/`sendBulk` (see
 *    {@see parseSendResponse()} and {@see parseBulkResponse()}):
 *      Shape A: `{"responses": [{"respose-code": 200, ...}]}`
 *      Shape B: `{"response-code": 1003, "errors": {...}}` (HTTP 422)
 *      Shape C: unparseable (HTML, 5xx body)
 *
 * Side-effects (decoupled from the host application per Reflection
 * §3.1 / §3.2):
 *
 *  - **Three Laravel events** are fired at the previously-coupled audit
 *    points: {@see SmsDispatchAccepted}, {@see SmsDispatchFailed},
 *    {@see SmsDeliveryReported}. The package itself never subscribes a
 *    listener — that is the host application's responsibility.
 *  - **PSR-3 logger** for debug/warning lines only (e.g. "DLR for unknown
 *    messageid"). All audit-grade signal goes through the events.
 *
 * Benchmark: ported from `app/Services/Sms/Gateways/TextSmsGateway.php`
 * in QPIMS, with the receipt-coupled helpers
 * (`syncReceiptFromDeliveryReport`, `resolveReceiptForDispatch`,
 * `isSmsResendReason`) and the `ActivityLogger` calls removed.
 */
class TextSmsGateway implements SmsDeliveryReportProcessor, SmsSender
{
    public const PROVIDER = 'textsms';

    private const BULK_LIMIT = 20;

    private const ENDPOINT_SEND = '/api/services/sendsms/';

    private const ENDPOINT_BULK = '/api/services/sendbulk/';

    private const ENDPOINT_BALANCE = '/api/services/getbalance/';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $partnerId,
        private readonly string $defaultSenderId,
        private readonly PhoneNumberNormalizer $normalizer,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        $normalised = $this->requireValidRecipient($recipient);
        $body = $this->requireNonEmptyBody($message->body);
        $senderId = $message->senderId ?? $this->defaultSenderId;
        $clientId = $message->clientReference ?? $this->generateClientId();

        $dispatch = $this->preInsert(
            recipient: $normalised,
            senderId: $senderId,
            body: $body,
            clientId: $clientId,
            notifiable: $notifiable,
            metadata: $message->metadata,
        );

        try {
            $response = $this->http()->post(self::ENDPOINT_SEND, [
                'apikey' => $this->apiKey,
                'partnerID' => $this->partnerId,
                'mobile' => $normalised,
                'message' => $body,
                'shortcode' => $senderId,
                'clientsmsid' => $clientId,
                'pass_type' => 'plain',
            ]);

            $parsed = $this->parseSendResponse($response);
            $code = (string) ($parsed['code'] ?? '');
            $description = (string) ($parsed['description'] ?? '');

            if ($parsed['source'] === 'responses' && $code === '200') {
                $this->markSent($dispatch, $parsed['row']);
                SmsDispatchAccepted::dispatch($dispatch->refresh());

                return $dispatch;
            }

            $this->markFailed(
                dispatch: $dispatch,
                code: $code !== '' ? $code : null,
                description: $description !== '' ? $description : null,
                providerErrors: $parsed['errors'],
                parseSource: $parsed['source'],
                diagnostic: $parsed['row'],
            );
            SmsDispatchFailed::dispatch(
                $dispatch->refresh(),
                $code !== '' ? $code : null,
                $description !== '' ? $description : null,
                $parsed['errors'],
                $parsed['source'],
            );

            throw new SmsProviderException(
                message: "TextSMS rejected dispatch ({$code}): {$description}",
                providerCode: $code !== '' ? $code : null,
                providerDescription: $description !== '' ? $description : null,
                providerErrors: $parsed['errors'],
            );
        } catch (SmsProviderException $e) {
            throw $e;
        } catch (ConnectionException|RequestException $e) {
            $this->markFailed($dispatch, code: null, description: $e->getMessage());
            SmsDispatchFailed::dispatch($dispatch->refresh(), null, $e->getMessage());

            throw new SmsProviderException(
                message: 'TextSMS HTTP error: '.$e->getMessage(),
                previous: $e,
            );
        } catch (Throwable $e) {
            $this->markFailed($dispatch, code: null, description: $e->getMessage());
            SmsDispatchFailed::dispatch($dispatch->refresh(), null, $e->getMessage());

            throw new SmsProviderException(
                message: 'TextSMS unexpected error: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     */
    public function sendBulk(array $items): Collection
    {
        if ($items === []) {
            return collect();
        }

        if (count($items) > self::BULK_LIMIT) {
            throw new SmsDispatchException(
                'TextSMS bulk endpoint accepts a maximum of '.self::BULK_LIMIT.' messages per call. '
                .'Caller is responsible for batching.'
            );
        }

        $rows = collect();
        $smslist = [];

        foreach ($items as $item) {
            $normalised = $this->requireValidRecipient($item['recipient']);
            $message = $item['message'];
            $body = $this->requireNonEmptyBody($message->body);
            $senderId = $message->senderId ?? $this->defaultSenderId;
            $clientId = $message->clientReference ?? $this->generateClientId();

            $dispatch = $this->preInsert(
                recipient: $normalised,
                senderId: $senderId,
                body: $body,
                clientId: $clientId,
                notifiable: $item['notifiable'] ?? null,
                metadata: $message->metadata,
            );
            $rows->push($dispatch);

            $smslist[] = [
                'partnerID' => $this->partnerId,
                'apikey' => $this->apiKey,
                'pass_type' => 'plain',
                'clientsmsid' => $clientId,
                'mobile' => $normalised,
                'message' => $body,
                'shortcode' => $senderId,
            ];
        }

        try {
            $response = $this->http()->post(self::ENDPOINT_BULK, [
                'count' => count($smslist),
                'smslist' => $smslist,
            ]);

            $parsed = $this->parseBulkResponse($response);

            if ($parsed['global'] !== null) {
                // Provider rejected the entire batch (validation or unparseable).
                foreach ($rows as $dispatch) {
                    $this->markFailed(
                        dispatch: $dispatch,
                        code: $parsed['global']['code'] ?? null,
                        description: $parsed['global']['description'] ?? null,
                        providerErrors: $parsed['global']['errors'] ?? null,
                        parseSource: $parsed['global']['source'],
                        diagnostic: $parsed['global']['diagnostic'] ?? null,
                    );
                    SmsDispatchFailed::dispatch(
                        $dispatch->refresh(),
                        $parsed['global']['code'] ?? null,
                        $parsed['global']['description'] ?? null,
                        $parsed['global']['errors'] ?? null,
                        $parsed['global']['source'],
                    );
                }

                throw new SmsProviderException(
                    message: 'TextSMS bulk rejected ('.($parsed['global']['code'] ?? 'unknown').'): '
                        .($parsed['global']['description'] ?? ''),
                    providerCode: $parsed['global']['code'] ?? null,
                    providerDescription: $parsed['global']['description'] ?? null,
                    providerErrors: $parsed['global']['errors'] ?? null,
                );
            }

            foreach ($rows as $dispatch) {
                $payload = $parsed['perClient'][(string) $dispatch->client_message_id] ?? [];
                $code = (string) ($payload['respose-code'] ?? $payload['response-code'] ?? '');
                $description = (string) ($payload['response-description'] ?? '');

                if ($code === '200') {
                    $this->markSent($dispatch, $payload);
                    SmsDispatchAccepted::dispatch($dispatch->refresh());
                } else {
                    $this->markFailed(
                        dispatch: $dispatch,
                        code: $code !== '' ? $code : null,
                        description: $description !== '' ? $description : null,
                    );
                    SmsDispatchFailed::dispatch(
                        $dispatch->refresh(),
                        $code !== '' ? $code : null,
                        $description !== '' ? $description : null,
                    );
                }
            }
        } catch (ConnectionException|RequestException $e) {
            // Bulk-wide failure: every pre-inserted row gets marked failed.
            foreach ($rows as $dispatch) {
                $this->markFailed($dispatch, code: null, description: $e->getMessage());
                SmsDispatchFailed::dispatch($dispatch->refresh(), null, $e->getMessage());
            }
            throw new SmsProviderException(
                message: 'TextSMS bulk HTTP error: '.$e->getMessage(),
                previous: $e,
            );
        }

        return $rows->map(fn (SmsDispatch $row): SmsDispatch => $row->refresh());
    }

    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        $messageId = (string) ($payload['messageid'] ?? $payload['messageID'] ?? '');

        if ($messageId === '') {
            $this->logger->warning('TextSmsGateway: DLR payload missing messageid', ['payload' => $payload]);

            return null;
        }

        $dispatch = SmsDispatch::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_message_id', $messageId)
            ->first();

        if ($dispatch === null) {
            $this->logger->info('TextSmsGateway: DLR for unknown messageid', ['messageid' => $messageId]);

            return null;
        }

        $rawStatus = strtolower((string) ($payload['status'] ?? $payload['dlrstatus'] ?? ''));
        $deliveryStatus = $this->classifyDeliveryReportStatus($rawStatus);
        $now = now();

        $updates = ['dlr_payload' => $payload];

        if ($deliveryStatus === 'delivered') {
            $updates['status'] = 'delivered';
            $updates['delivered_at'] = $now;
        } elseif ($deliveryStatus === 'failed') {
            $updates['status'] = 'failed';
            $updates['failed_at'] = $now;
        }
        // For 'unknown' (e.g. enroute, buffered) we persist the payload but
        // leave status untouched.

        $dispatch->update($updates);
        $dispatch->refresh();

        SmsDeliveryReported::dispatch(
            $dispatch,
            $deliveryStatus,
            $rawStatus,
            $payload,
        );

        return $dispatch;
    }

    public function balance(): array
    {
        try {
            $response = $this->http()->post(self::ENDPOINT_BALANCE, [
                'apikey' => $this->apiKey,
                'partnerID' => $this->partnerId,
            ]);

            return (array) $response->json();
        } catch (Throwable $e) {
            throw new SmsProviderException(
                message: 'TextSMS balance lookup failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Build the underlying HTTP client. Centralised so retry / timeout
     * settings stay consistent across send / sendBulk / balance.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->asJson()
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->retry(2, 200, throw: false);
    }

    private function requireValidRecipient(string $recipient): string
    {
        $normalised = $this->normalizer->normalize($recipient);

        if ($normalised === null) {
            throw new SmsDispatchException("Invalid recipient phone number: {$recipient}");
        }

        return $normalised;
    }

    private function requireNonEmptyBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            throw new SmsDispatchException('SMS body cannot be empty.');
        }

        return $trimmed;
    }

    /**
     * Generate a unique `clientsmsid` round-tripped through TextSMS so the
     * gateway can correlate bulk responses back to rows. ULID gives us
     * collision-free identifiers even under concurrent bulk fan-out.
     */
    private function generateClientId(): string
    {
        return (string) Str::ulid()->toBase32();
    }

    /**
     * Insert a `pending` row before the HTTP call. Every send leaves an
     * audit trail even if the network call dies mid-flight.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function preInsert(
        string $recipient,
        string $senderId,
        string $body,
        string $clientId,
        ?Model $notifiable,
        array $metadata,
    ): SmsDispatch {
        $row = new SmsDispatch([
            'provider' => self::PROVIDER,
            'recipient' => $recipient,
            'sender_id' => $senderId,
            'body' => $body,
            'client_message_id' => $clientId,
            'status' => 'pending',
            'attempt_count' => 1,
            'metadata' => $metadata,
        ]);

        if ($notifiable !== null) {
            $row->notifiable_type = $notifiable::class;
            $row->notifiable_id = $notifiable->getKey();
        }

        $row->save();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markSent(SmsDispatch $dispatch, array $payload): void
    {
        $dispatch->update([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_id' => isset($payload['messageid']) ? (string) $payload['messageid'] : null,
            'network_id' => isset($payload['networkid']) ? (string) $payload['networkid'] : null,
            'response_code' => (string) ($payload['respose-code'] ?? $payload['response-code'] ?? '200'),
            'response_description' => (string) ($payload['response-description'] ?? 'Success'),
        ]);
    }

    /**
     * Persist a failed dispatch with optional structured diagnostics.
     *
     * Diagnostic fields are merged into the row's `metadata` JSON column
     * under the namespaced key `provider_diagnostics`, which carries the
     * provider name as a nested field so the block is self-describing when
     * inspected in isolation. The verbose key avoids collision with
     * caller-supplied {@see SmsMessage::$metadata}, which is preserved via
     * `array_merge`.
     *
     * @param  array<string, mixed>|null  $providerErrors  Structured `errors` map from validation responses.
     * @param  string|null  $parseSource  'responses' | 'top-level' | 'unparseable'.
     * @param  array<string, mixed>|null  $diagnostic  Synthesised diagnostic stub (carries `_http_status`, `_raw_body_truncated`).
     */
    private function markFailed(
        SmsDispatch $dispatch,
        ?string $code,
        ?string $description,
        ?array $providerErrors = null,
        ?string $parseSource = null,
        ?array $diagnostic = null,
    ): void {
        $updates = [
            'status' => 'failed',
            'failed_at' => now(),
            'response_code' => $code,
            'response_description' => $description,
        ];

        $existingMeta = $dispatch->metadata ?? [];
        $diagnosticsBlock = array_filter([
            'provider' => self::PROVIDER,
            'parse_source' => $parseSource,
            'errors' => $providerErrors,
            'http_status' => $diagnostic['_http_status'] ?? null,
            'raw_body_truncated' => $diagnostic['_raw_body_truncated'] ?? null,
        ], fn ($v) => $v !== null);

        if (count($diagnosticsBlock) > 1) {
            $updates['metadata'] = array_merge(
                $existingMeta,
                ['provider_diagnostics' => $diagnosticsBlock],
            );
        }

        $dispatch->update($updates);
    }

    /**
     * Classify a raw DLR status into one of `delivered`, `failed`, or
     * `unknown`. Failure terms are checked FIRST so `undelivered` is not
     * misclassified by the broader `deliver` substring (a real production
     * bug — see `.docs/sms-gateway/07-validation-error-parsing.md` in the
     * QPIMS benchmark).
     */
    private function classifyDeliveryReportStatus(string $rawStatus): string
    {
        if (
            str_contains($rawStatus, 'undeliver')
            || str_contains($rawStatus, 'undeliv')
            || str_contains($rawStatus, 'fail')
            || str_contains($rawStatus, 'expir')
            || str_contains($rawStatus, 'reject')
        ) {
            return 'failed';
        }

        if (
            str_contains($rawStatus, 'deliver')
            || str_contains($rawStatus, 'delivrd')
            || str_contains($rawStatus, 'deliv')
        ) {
            return 'delivered';
        }

        return 'unknown';
    }

    /**
     * Parse a single-send TextSMS response into a structured outcome
     * regardless of shape.
     *
     * Shape A (per-message) : {"responses": [{"respose-code": 200, ...}]}
     * Shape B (top-level)   : {"response-code": 1003, "response-description": ..., "errors": {...}}
     * Shape C (unparseable) : HTTP error with no JSON / empty body / unknown keys.
     *
     * @return array{source: string, code: ?string, description: ?string, errors: array<string, mixed>|null, row: array<string, mixed>}
     */
    private function parseSendResponse(Response $response): array
    {
        $responses = $response->json('responses');

        if (is_array($responses) && $responses !== []) {
            $row = $responses[0] ?? [];
            $code = isset($row['respose-code']) ? (string) $row['respose-code']
                : (isset($row['response-code']) ? (string) $row['response-code'] : null);
            $description = isset($row['response-description']) ? (string) $row['response-description'] : null;

            return [
                'source' => 'responses',
                'code' => $code,
                'description' => $description,
                'errors' => null,
                'row' => $row,
            ];
        }

        $top = $response->json();

        if (is_array($top) && isset($top['response-code'])) {
            $code = (string) $top['response-code'];
            $description = isset($top['response-description']) ? (string) $top['response-description'] : null;
            $errors = (isset($top['errors']) && is_array($top['errors'])) ? $top['errors'] : null;

            return [
                'source' => 'top-level',
                'code' => $code,
                'description' => $description,
                'errors' => $errors,
                'row' => [
                    'respose-code' => $top['response-code'],
                    'response-description' => $description,
                    'errors' => $errors,
                ],
            ];
        }

        return [
            'source' => 'unparseable',
            'code' => null,
            'description' => 'Unparseable TextSMS response (HTTP '.$response->status().')',
            'errors' => null,
            'row' => [
                '_http_status' => $response->status(),
                '_raw_body_truncated' => mb_substr((string) $response->body(), 0, 200),
            ],
        ];
    }

    /**
     * Parse a bulk-send response into a per-client map plus an optional
     * global-failure outcome (provider rejected the whole batch).
     *
     * Shape C (unparseable) at the bulk endpoint carries the same
     * diagnostic stub as single-send so a batch-wide failure leaves the
     * same trail in `metadata.provider_diagnostics`.
     *
     * @return array{global: ?array{source: string, code: ?string, description: ?string, errors: array<string, mixed>|null, diagnostic: array<string, mixed>|null}, perClient: array<string, array<string, mixed>>}
     */
    private function parseBulkResponse(Response $response): array
    {
        $responses = $response->json('responses');

        if (is_array($responses) && $responses !== []) {
            $perClient = collect($responses)
                ->keyBy(fn ($r) => (string) ($r['clientsmsid'] ?? ''))
                ->all();

            return ['global' => null, 'perClient' => $perClient];
        }

        $top = $response->json();

        if (is_array($top) && isset($top['response-code'])) {
            return [
                'global' => [
                    'source' => 'top-level',
                    'code' => (string) $top['response-code'],
                    'description' => isset($top['response-description']) ? (string) $top['response-description'] : null,
                    'errors' => (isset($top['errors']) && is_array($top['errors'])) ? $top['errors'] : null,
                    'diagnostic' => null,
                ],
                'perClient' => [],
            ];
        }

        return [
            'global' => [
                'source' => 'unparseable',
                'code' => null,
                'description' => 'Unparseable TextSMS bulk response (HTTP '.$response->status().')',
                'errors' => null,
                'diagnostic' => [
                    '_http_status' => $response->status(),
                    '_raw_body_truncated' => mb_substr((string) $response->body(), 0, 200),
                ],
            ],
            'perClient' => [],
        ];
    }
}
