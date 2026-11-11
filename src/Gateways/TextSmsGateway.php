<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\TransferStats;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
 *  - **`200` needs a usable success response.** Acceptance also requires
 *    a usable provider ID, successful HTTP status and unambiguous mapping.
 *    `1003` means invalid mobile number; `1004` low credits; `1006` invalid
 *    credentials. `1008` means no delivery report, while `4090` is an
 *    internal error (provider advice: try again after five minutes).
 *    Those last two are not confirmed send refusals.
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
 *  - **Two outbound Laravel events** report saved submission outcomes:
 *    {@see SmsDispatchAccepted} and {@see SmsDispatchFailed}. The package itself never subscribes a
 *    listener — that is the host application's responsibility.
 *  - **PSR-3 logger** for debug/warning lines (e.g. "DLR for unknown
 *    messageid") and for the error entry that carries recovery context
 *    when a result cannot be recorded locally.
 *
 * Send and bulk-send run in three separate stages so that nothing local
 * can be mistaken for a provider outcome:
 *
 *  1. **Provider I/O and classification.** Only this stage can produce a
 *     provider or transport failure.
 *  2. **Recording.** Recording is attempted for every row's classified
 *     outcome, even after another row fails. A storage or model-observer
 *     failure here is a *local recording* failure: the provider outcome is
 *     never rewritten, and no second request is sent.
 *  3. **Publication.** One lifecycle event per successfully recorded row,
 *     in input order. Exceptions raised during immediate event dispatch
 *     are reported through Laravel's
 *     exception handler and never change the result (owner decision D1-A);
 *     a failure of the reporting itself is dropped.
 *     Deferred after-commit listeners/queue submission and queue workers
 *     execute outside this wrapper; their failures belong to the host.
 *
 * Outcomes carry a certainty: `accepted` (usable code-200 response), `rejected` (a
 * documented send-rejection code, see {@see self::SEND_REJECTION_CODES}), or
 * unknown (transport failure, unparseable or unmatched output, or any other
 * code). Unknown outcomes are still recorded as `failed`, but recovery
 * messages treat them as possibly sent.
 *
 * Delivery reports update only the informational payload, without model
 * observers, lifecycle events or submission-state/timestamp changes. Storage
 * failures propagate. Send retries are bounded to one additional attempt
 * only for structured cURL 6/7 connection failures; redirects are disabled.
 *
 * Benchmark: ported from `app/Services/Sms/Gateways/TextSmsGateway.php`
 * in QPIMS, with the receipt-coupled helpers
 * (`syncReceiptFromDeliveryReport`, `resolveReceiptForDispatch`,
 * `isSmsResendReason`) and the `ActivityLogger` calls removed.
 *
 * @phpstan-type DispatchOutcome array{
 *     accepted: bool,
 *     kind: 'accepted'|'rejected'|'unknown_response'|'transport_error'|'unexpected_error',
 *     payload: array<string, mixed>,
 *     code: ?string,
 *     description: ?string,
 *     errors: array<string, mixed>|null,
 *     source: ?string,
 *     diagnostic: array<string, mixed>|null,
 *     provider_message_id: ?string,
 * }
 * @phpstan-type ParsedResponse array{source: string, code: ?string, description: ?string, errors: array<string, mixed>|null, row: array<string, mixed>}
 * @phpstan-type ParsedBulkResponse array{global: ParsedResponse|null, perClient: array<string, ParsedResponse>, anomalies: array<string, int>}
 */
class TextSmsGateway implements SmsDeliveryReportProcessor, SmsSender
{
    public const PROVIDER = 'textsms';

    private const BULK_LIMIT = 20;

    /**
     * Width of the `response_description` column, in characters.
     */
    private const DESCRIPTION_LIMIT = 255;

    /**
     * Provider codes that prove TextSMS refused a send request, so nothing
     * was sent. Source: the TextSMS Bulk SMS API code table
     * (`docs/text-sms-gateway-api-docs/Bulk SMS API FOR TEXTSMS (3).pdf`, p. 4):
     * 1001 invalid sender id, 1002 network not allowed, 1003 invalid mobile
     * number (also returned for validation errors), 1004 low bulk credits,
     * 1006 invalid credentials, 1009 unsupported data type, 1010 unsupported
     * request type, 4091 no partner id, 4092 no API key.
     *
     * Deliberately excluded, and therefore treated as an unknown outcome:
     * 1005 / 1007 "Failed. System error" and 4090 "Internal Error" (a
     * server-side failure does not prove the message was not dispatched),
     * 1008 "No Delivery Report" and 4093 "Details Not Found" (they describe
     * other operations). Live provider evidence (T-07) may refine this list.
     *
     * @var list<string>
     */
    private const SEND_REJECTION_CODES = ['1001', '1002', '1003', '1004', '1006', '1009', '1010', '4091', '4092'];

    private const ENDPOINT_SEND = '/api/services/sendsms/';

    private const ENDPOINT_BULK = '/api/services/sendbulk/';

    private const ENDPOINT_BALANCE = '/api/services/getbalance/';

    /** Configure provider transport and the existing normalization/logging boundaries. */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $partnerId,
        private readonly string $defaultSenderId,
        private readonly PhoneNumberNormalizer $normalizer,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /** {@inheritDoc} */
    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        $normalised = $this->requireValidRecipient($recipient);
        $body = $this->requireNonEmptyBody($message->body);
        $senderId = $message->senderId ?? $this->defaultSenderId;
        $clientId = $message->clientReference ?? $this->generateClientId();
        $this->requireCredentials();

        $dispatch = $this->preInsert(
            recipient: $normalised,
            senderId: $senderId,
            body: $body,
            clientId: $clientId,
            notifiable: $notifiable,
            metadata: $message->metadata,
        );

        // Stage 1 — provider I/O and classification only. Storage and
        // consumer listeners run later, so they can never be mistaken for a
        // provider failure.
        try {
            $response = $this->http(sending: true)->post(self::ENDPOINT_SEND, [
                'apikey' => $this->apiKey,
                'partnerID' => $this->partnerId,
                'mobile' => $normalised,
                'message' => $body,
                'shortcode' => $senderId,
                'clientsmsid' => $clientId,
                'pass_type' => 'plain',
            ]);

            [$outcome, $failure] = $this->classifySend($this->parseSendResponse($response, $clientId));
        } catch (ConnectionException|RequestException $e) {
            $outcome = $this->failedOutcome('transport_error', code: null, description: $e->getMessage());
            $failure = new SmsProviderException(
                message: 'TextSMS HTTP error: '.$e->getMessage(),
                previous: $e,
            );
        } catch (Throwable $e) {
            $outcome = $this->failedOutcome('unexpected_error', code: null, description: $e->getMessage());
            $failure = new SmsProviderException(
                message: 'TextSMS unexpected error: '.$e->getMessage(),
                previous: $e,
            );
        }

        $this->warnResponseAnomalies([$outcome], [], 'single');

        // Stages 2 and 3 — record, then publish.
        return $this->finalize([$dispatch], [$outcome], $failure)[0];
    }

    /**
     * Validate the complete batch, prepare rows atomically, then correlate provider outcomes.
     *
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

        $prepared = $this->prepareBulkItems($items);
        $this->requireCredentials();
        try {
            $rows = (new SmsDispatch)->getConnection()->transaction(function () use ($prepared): array {
                $rows = [];
                foreach ($prepared as $item) {
                    $rows[] = $this->preInsert(
                        recipient: $item['recipient'],
                        senderId: $item['sender_id'],
                        body: $item['body'],
                        clientId: $item['client_id'],
                        notifiable: $item['notifiable'],
                        metadata: $item['metadata'],
                    );
                }

                return $rows;
            });
        } catch (SmsProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsProviderException('The local bulk dispatch records could not be prepared; no request was sent to TextSMS.', previous: $e);
        }

        $smslist = array_map(fn (array $item): array => [
            'partnerID' => $this->partnerId,
            'apikey' => $this->apiKey,
            'pass_type' => 'plain',
            'clientsmsid' => $item['client_id'],
            'mobile' => $item['recipient'],
            'message' => $item['body'],
            'shortcode' => $item['sender_id'],
        ], $prepared);
        $anomalies = [];

        // Stage 1 — provider I/O and classification only.
        try {
            $response = $this->http(sending: true)->post(self::ENDPOINT_BULK, [
                'count' => count($smslist),
                'smslist' => $smslist,
            ]);

            $parsed = $this->parseBulkResponse($response, array_column($prepared, 'client_id'));
            $anomalies = $parsed['anomalies'];
            [$outcomes, $failure] = $this->classifyBulk($rows, $parsed);
        } catch (ConnectionException|RequestException $e) {
            // Bulk-wide transport failure: every row gets the same outcome.
            $outcomes = array_map(
                fn (): array => $this->failedOutcome('transport_error', code: null, description: $e->getMessage()),
                $rows,
            );
            $failure = new SmsProviderException(
                message: 'TextSMS bulk HTTP error: '.$e->getMessage(),
                previous: $e,
            );
        }

        $this->warnResponseAnomalies($outcomes, $anomalies, 'bulk');

        // Stages 2 and 3 — record every row, then publish.
        return collect($this->finalize($rows, $outcomes, $failure));
    }

    /**
     * Validate and normalize the entire batch before any write or HTTP call.
     *
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     * @return list<array{recipient: string, sender_id: string, body: string, client_id: string, notifiable: Model|null, metadata: array<string, mixed>}>
     */
    private function prepareBulkItems(array $items): array
    {
        $prepared = [];
        $clientIds = [];
        foreach ($items as $index => $item) {
            try {
                if (! is_array($item) || ! isset($item['recipient']) || ! is_string($item['recipient'])) {
                    throw new SmsDispatchException('recipient must be a string on an array item.');
                }
                if (! isset($item['message']) || ! $item['message'] instanceof SmsMessage) {
                    throw new SmsDispatchException('message must be an SmsMessage.');
                }
                $notifiable = $item['notifiable'] ?? null;
                if ($notifiable !== null && ! $notifiable instanceof Model) {
                    throw new SmsDispatchException('notifiable must be a model or null.');
                }
                $message = $item['message'];
                $recipient = $this->requireValidRecipient($item['recipient']);
                $body = $this->requireNonEmptyBody($message->body);
                $clientId = $message->clientReference ?? $this->generateClientId();
                if (trim($clientId) === '' || mb_strlen($clientId, 'UTF-8') > 255) {
                    throw new SmsDispatchException('client reference must be non-blank and at most 255 characters.');
                }
                if (in_array($clientId, $clientIds, true)) {
                    throw new SmsDispatchException('client reference must be unique within the batch.');
                }
                $senderId = $message->senderId ?? $this->defaultSenderId;
                try {
                    json_encode([$recipient, $senderId, $body, $clientId], JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new SmsDispatchException('outbound fields must be valid UTF-8.', previous: $e);
                }
                $clientIds[] = $clientId;
                $prepared[] = [
                    'recipient' => $recipient,
                    'sender_id' => $senderId,
                    'body' => $body,
                    'client_id' => $clientId,
                    'notifiable' => $notifiable,
                    'metadata' => $message->metadata,
                ];
            } catch (SmsDispatchException $e) {
                throw new SmsDispatchException("Bulk item {$index}: ".$e->getMessage(), previous: $e);
            }
        }

        return $prepared;
    }

    /** {@inheritDoc} */
    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        $messageId = (string) ($payload['messageid'] ?? $payload['messageID'] ?? '');

        if ($messageId === '') {
            $this->logger->warning('TextSmsGateway: DLR payload missing messageid', ['payload' => $payload]);

            return null;
        }

        // Reports are passive information. Suppress retrieval/write hooks
        // as well as automatic timestamps; only the payload is recorded.
        return SmsDispatch::withoutEvents(fn (): ?SmsDispatch => SmsDispatch::withoutTimestamps(function () use ($messageId, $payload): ?SmsDispatch {
            $dispatch = SmsDispatch::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_message_id', $messageId)
                ->first();

            if ($dispatch === null) {
                $this->logger->info('TextSmsGateway: DLR for unknown messageid', ['messageid' => $messageId]);

                return null;
            }

            if (! $dispatch->update(['dlr_payload' => $payload])) {
                throw new RuntimeException('The delivery report could not be recorded locally.');
            }

            return $dispatch;
        }));
    }

    /** Return a successful balance object; reject HTTP, provider and malformed responses. */
    public function balance(): array
    {
        $this->requireCredentials();

        try {
            $response = $this->http()->post(self::ENDPOINT_BALANCE, [
                'apikey' => $this->apiKey,
                'partnerID' => $this->partnerId,
            ]);

            $payload = $response->json();
            $code = is_array($payload) ? $this->providerString($payload['response-code'] ?? null, 8) : null;
            $description = is_array($payload) ? $this->providerString($payload['response-description'] ?? null) : null;
            $errors = is_array($payload) && is_array($payload['errors'] ?? null) ? $payload['errors'] : null;

            if (! $response->successful() || ! is_array($payload) || $payload === [] || array_is_list($payload)
                || (array_key_exists('response-code', $payload) && $code !== '200')
                || (isset($payload['errors']) && $payload['errors'] !== [])) {
                throw new SmsProviderException(
                    'TextSMS balance lookup returned an unsuccessful or invalid response (HTTP '.$response->status().').',
                    providerCode: $code,
                    providerDescription: $description,
                    providerErrors: $errors,
                );
            }

            // The live account's balance fields are not established here.
            // Preserve the successful free-form object rather than inventing a schema.
            return $payload;
        } catch (SmsProviderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new SmsProviderException(
                message: 'TextSMS balance lookup failed.',
                previous: $e,
            );
        }
    }

    /** Fail outbound operations before preparing rows or contacting the provider. */
    private function requireCredentials(): void
    {
        $missing = [];
        if (trim($this->apiKey) === '') {
            $missing[] = 'api_key';
        }
        if (trim($this->partnerId) === '') {
            $missing[] = 'partner_id';
        }
        if ($missing !== []) {
            throw new SmsProviderException('TextSMS credentials are missing ('.implode(', ', $missing).'); no request was sent.');
        }
    }

    /**
     * Classify a parsed single-send response. Pure: no I/O, no storage.
     *
     * @param  ParsedResponse  $parsed
     * @return array{0: DispatchOutcome, 1: SmsProviderException|null}
     */
    private function classifySend(array $parsed): array
    {
        $outcome = $this->responseOutcome($parsed);

        return [$outcome, $outcome['accepted'] ? null : $this->providerFailure($outcome, false)];
    }

    /**
     * Classify a parsed bulk response into one outcome per row, in input
     * order. Pure: no I/O, no storage.
     *
     * @param  list<SmsDispatch>  $rows
     * @param  ParsedBulkResponse  $parsed
     * @return array{0: list<DispatchOutcome>, 1: SmsProviderException|null}
     */
    private function classifyBulk(array $rows, array $parsed): array
    {
        $global = $parsed['global'];

        if ($global !== null) {
            $outcome = $this->responseOutcome($global);

            return [array_fill(0, count($rows), $outcome), $this->providerFailure($outcome, true)];
        }

        $outcomes = array_map(function (SmsDispatch $dispatch) use ($parsed): array {
            $response = $parsed['perClient'][(string) $dispatch->client_message_id] ?? [
                'source' => 'unmatched',
                'code' => null,
                'description' => 'No provider response matched this client reference.',
                'errors' => null,
                'row' => [],
            ];

            return $this->responseOutcome($response);
        }, $rows);

        return [$outcomes, null];
    }

    /**
     * @param  ParsedResponse  $parsed
     * @return DispatchOutcome
     */
    private function responseOutcome(array $parsed): array
    {
        if ($parsed['source'] === 'responses' && $parsed['code'] === '200') {
            return $this->acceptedOutcome($parsed['row']);
        }

        return $this->failedOutcome(
            in_array($parsed['source'], ['responses', 'top-level'], true)
                ? $this->rejectionKind($parsed['code'] ?? '')
                : 'unknown_response',
            code: $parsed['code'],
            description: $parsed['description'],
            errors: $parsed['errors'],
            source: $parsed['source'],
            diagnostic: $parsed['row'],
        );
    }

    /** @param DispatchOutcome $outcome */
    private function providerFailure(array $outcome, bool $bulk): SmsProviderException
    {
        $subject = $bulk ? 'bulk' : 'dispatch';
        $detail = '('.($outcome['code'] ?? 'unknown').'): '.($outcome['description'] ?? 'No usable provider result');
        $message = $outcome['kind'] === 'rejected'
            ? "TextSMS rejected {$subject} {$detail}"
            : "TextSMS outcome is unknown for {$subject} {$detail}. TextSMS may have accepted the SMS; resending automatically is unsafe.";

        return new SmsProviderException(
            message: $message,
            providerCode: $outcome['code'],
            providerDescription: $outcome['description'],
            providerErrors: $outcome['errors'],
        );
    }

    /**
     * @param  list<DispatchOutcome>  $outcomes
     * @param  array<string, int>  $anomalies
     */
    private function warnResponseAnomalies(array $outcomes, array $anomalies, string $operation): void
    {
        foreach ($outcomes as $outcome) {
            if ($outcome['kind'] === 'unknown_response') {
                $source = $outcome['source'] ?? 'unknown';
                $anomalies[$source] = ($anomalies[$source] ?? 0) + 1;
            }
        }
        if ($anomalies !== []) {
            rescue(fn () => $this->logger->warning('TextSmsGateway: uncertain provider response', [
                'provider' => self::PROVIDER,
                'operation' => $operation,
                'anomalies' => $anomalies,
            ]), report: false);
        }
    }

    /**
     * A non-accepted outcome is a confirmed rejection only when TextSMS
     * returned one of its documented send-rejection codes
     * ({@see self::SEND_REJECTION_CODES}). Anything else — no code, a stray
     * `200` outside the accepted shape, a corrupted or undocumented value,
     * a server-side error, or a code that describes another operation — is
     * no usable answer: the message may have been sent.
     *
     * @return 'rejected'|'unknown_response'
     */
    private function rejectionKind(string $code): string
    {
        return in_array($code, self::SEND_REJECTION_CODES, true) ? 'rejected' : 'unknown_response';
    }

    /**
     * @param  array<string, mixed>  $payload  The provider's per-message response row.
     * @return DispatchOutcome
     */
    private function acceptedOutcome(array $payload): array
    {
        return [
            'accepted' => true,
            'kind' => 'accepted',
            'payload' => $payload,
            'code' => (string) ($payload['respose-code'] ?? $payload['response-code'] ?? '200'),
            'description' => null,
            'errors' => null,
            'source' => null,
            'diagnostic' => null,
            'provider_message_id' => $this->providerMessageId($payload),
        ];
    }

    /**
     * @param  'rejected'|'unknown_response'|'transport_error'|'unexpected_error'  $kind
     * @param  array<string, mixed>|null  $errors
     * @param  array<string, mixed>|null  $diagnostic
     * @return DispatchOutcome
     */
    private function failedOutcome(
        string $kind,
        ?string $code,
        ?string $description,
        ?array $errors = null,
        ?string $source = null,
        ?array $diagnostic = null,
    ): array {
        return [
            'accepted' => false,
            'kind' => $kind,
            'payload' => [],
            'code' => $code,
            'description' => $description,
            'errors' => $errors,
            'source' => $source,
            'diagnostic' => $diagnostic,
            'provider_message_id' => null,
        ];
    }

    /**
     * Stage 2 — record every classified outcome. Every row is attempted,
     * even after another row's record fails. Stage 3 — publish one event
     * per recorded row, in input order. Then throw the local-recording
     * failure (when any row could not be recorded) or the provider failure,
     * or return the rows.
     *
     * A row whose record did not complete gets no compensating write, no
     * second provider call, and no event. Its persisted state is whatever
     * storage kept: still `pending`, or already updated when a post-write
     * observer or the re-read failed.
     *
     * @param  list<SmsDispatch>  $rows
     * @param  list<DispatchOutcome>  $outcomes
     * @return list<SmsDispatch>
     *
     * @throws SmsProviderException
     */
    private function finalize(array $rows, array $outcomes, ?SmsProviderException $failure): array
    {
        $recorded = [];
        $unrecorded = [];
        $firstError = null;

        foreach ($rows as $index => $dispatch) {
            $outcome = $outcomes[$index];

            $error = $this->record($dispatch, fn (): bool => $outcome['accepted']
                ? $this->markSent($dispatch, $outcome['payload'])
                : $this->markFailed(
                    dispatch: $dispatch,
                    code: $outcome['code'],
                    description: $outcome['description'],
                    providerErrors: $outcome['errors'],
                    parseSource: $outcome['source'],
                    diagnostic: $outcome['diagnostic'],
                    providerAcceptance: $this->acceptance($outcome),
                ));

            if ($error === null) {
                $recorded[$index] = true;

                continue;
            }

            $firstError ??= $error;
            $unrecorded[] = $this->recoveryContext($dispatch, $outcome);
        }

        foreach ($rows as $index => $dispatch) {
            if (! isset($recorded[$index])) {
                continue;
            }

            $outcome = $outcomes[$index];

            $this->publish($outcome['accepted']
                ? new SmsDispatchAccepted($dispatch)
                : new SmsDispatchFailed($dispatch, $outcome['code'], $outcome['description'], $outcome['errors'], $outcome['source']));
        }

        if ($firstError !== null && $unrecorded !== []) {
            throw $this->recordingFailure($unrecorded, $outcomes, $firstError, $failure);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $rows;
    }

    /**
     * Run one local write, then re-read the row. Returns the failure
     * instead of throwing, so the caller can keep recording other rows.
     * A write that a model event listener cancelled counts as a failure.
     *
     * @param  Closure(): bool  $write
     */
    private function record(SmsDispatch $dispatch, Closure $write): ?Throwable
    {
        try {
            if (! $write()) {
                throw new RuntimeException('A model event listener cancelled the SmsDispatch update.');
            }

            $dispatch->refresh();

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    /**
     * Publish a lifecycle event. A listener exception is reported through
     * Laravel's exception handler and never changes the recorded outcome,
     * the return value, or the provider exception (owner decision D1-A).
     *
     * Best effort: Laravel stops calling an event's remaining listeners
     * after the first synchronous listener throws — including listeners
     * that would have queued themselves.
     */
    private function publish(object $event): void
    {
        rescue(
            fn () => event($event),
            fn (Throwable $listenerError) => $this->reportQuietly($listenerError),
            report: false,
        );
    }

    /**
     * Report an exception through Laravel's exception handler, best effort.
     * If the handler itself fails (a broken reporting callback or error
     * tracker), that failure is dropped: diagnostics must never replace
     * the send result or the provider exception.
     */
    private function reportQuietly(Throwable $exception): void
    {
        rescue(fn () => report($exception), report: false);
    }

    /**
     * How certain the gateway is that TextSMS accepted a message:
     *
     *  - `accepted` — TextSMS answered with code 200 for it.
     *  - `rejected` — TextSMS answered with a documented send-rejection code
     *    ({@see self::SEND_REJECTION_CODES}).
     *  - `unknown`  — no usable answer (transport failure, unparseable or
     *    unmatched output, no code, or any other code): the message may have
     *    been sent.
     *
     * Only `rejected` makes a resend safe from duplication.
     *
     * @param  DispatchOutcome  $outcome
     * @return 'accepted'|'rejected'|'unknown'
     */
    private function acceptance(array $outcome): string
    {
        return match ($outcome['kind']) {
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            default => 'unknown',
        };
    }

    /**
     * Bounded recovery context for one row whose outcome was not recorded:
     * identifiers and outcome only — never credentials, recipient, message
     * body, or the full provider response.
     *
     * @param  DispatchOutcome  $outcome
     * @return array{dispatch_id: mixed, dispatch_ulid: string, client_message_id: ?string, provider_outcome: string, provider_acceptance: string, provider_code: ?string, provider_message_id: ?string}
     */
    private function recoveryContext(SmsDispatch $dispatch, array $outcome): array
    {
        return [
            'dispatch_id' => $dispatch->getKey(),
            'dispatch_ulid' => $dispatch->ulid,
            'client_message_id' => $dispatch->client_message_id,
            'provider_outcome' => $outcome['kind'],
            'provider_acceptance' => $this->acceptance($outcome),
            'provider_code' => $outcome['code'],
            'provider_message_id' => $outcome['provider_message_id'],
        ];
    }

    /**
     * Build the exception for results that could not be recorded locally,
     * then log their recovery context. The message distinguishes known
     * acceptance, possible acceptance, and explicit rejection, because
     * resending an accepted message sends a duplicate. `previous` carries
     * the first storage failure.
     *
     * The exception is built first and the log is best effort: a failing
     * logger must not replace the exception or lose its cause.
     *
     * @param  non-empty-list<array{dispatch_id: mixed, dispatch_ulid: string, client_message_id: ?string, provider_outcome: string, provider_acceptance: string, provider_code: ?string, provider_message_id: ?string}>  $unrecorded
     * @param  list<DispatchOutcome>  $outcomes  Every outcome of the call, recorded or not.
     */
    private function recordingFailure(
        array $unrecorded,
        array $outcomes,
        Throwable $cause,
        ?SmsProviderException $failure,
    ): SmsProviderException {
        $total = count($outcomes);
        $acceptance = array_count_values(array_map(
            fn (array $outcome): string => $this->acceptance($outcome),
            $outcomes,
        ));

        $exception = new SmsProviderException(
            message: $total === 1
                ? $this->singleRecordingFailureMessage($unrecorded[0], $outcomes[0]['description'], $failure)
                : $this->bulkRecordingFailureMessage($unrecorded, $total, $failure, $acceptance),
            providerCode: $failure !== null ? $failure->providerCode : ($total === 1 ? $unrecorded[0]['provider_code'] : null),
            providerDescription: $failure?->providerDescription,
            providerErrors: $failure?->providerErrors,
            previous: $cause,
        );

        // A broken logger is not reported back through the same logger.
        rescue(fn () => $this->logger->error('TextSmsGateway: dispatch result could not be recorded locally', [
            'unrecorded' => $unrecorded,
            'total' => $total,
            'exception' => $cause::class,
        ]), report: false);

        return $exception;
    }

    /**
     * @param  array{dispatch_ulid: string, provider_outcome: string, provider_acceptance: string, provider_message_id: ?string}  $row
     * @param  string|null  $detail  The outcome description, e.g. the transport error.
     */
    private function singleRecordingFailureMessage(array $row, ?string $detail, ?SmsProviderException $failure): string
    {
        return match ($row['provider_acceptance']) {
            'accepted' => sprintf(
                'TextSMS accepted dispatch %s (provider message id %s), but recording the result locally failed. '
                .'The SMS was sent; resending it automatically is unsafe.',
                $row['dispatch_ulid'],
                $row['provider_message_id'] ?? 'not returned',
            ),
            'rejected' => sprintf(
                '%s. Recording this outcome locally also failed for dispatch %s.',
                rtrim($failure?->getMessage() ?? 'TextSMS dispatch failed', '.'),
                $row['dispatch_ulid'],
            ),
            default => sprintf(
                'TextSMS gave no usable outcome for dispatch %s: %s. Recording the outcome locally also failed. '
                .'TextSMS may have accepted the SMS; resending it automatically is unsafe.',
                $row['dispatch_ulid'],
                rtrim($detail ?? $row['provider_outcome'], '.'),
            ),
        };
    }

    /**
     * @param  non-empty-list<array{provider_acceptance: string, provider_message_id: ?string}>  $unrecorded
     * @param  array<string, int>  $acceptance
     */
    private function bulkRecordingFailureMessage(array $unrecorded, int $total, ?SmsProviderException $failure, array $acceptance): string
    {
        $message = sprintf(
            'Recording the TextSMS bulk result locally failed for %d of %d dispatches',
            count($unrecorded),
            $total,
        );

        $accepted = $acceptance['accepted'] ?? 0;
        $unknown = $acceptance['unknown'] ?? 0;

        $message .= match (true) {
            $accepted > 0 && $unknown > 0 => sprintf(
                '; resending the batch as a whole is unsafe: TextSMS accepted %d and may have accepted %d of the %d messages.',
                $accepted,
                $unknown,
                $total,
            ),
            $accepted > 0 => sprintf(
                '; resending the batch as a whole is unsafe: TextSMS accepted %d of the %d messages.',
                $accepted,
                $total,
            ),
            $unknown > 0 => sprintf(
                '; resending the batch as a whole is unsafe: TextSMS gave no usable outcome and may have accepted %d of the %d messages.',
                $unknown,
                $total,
            ),
            $failure !== null => '; TextSMS had rejected the whole batch: '.$failure->getMessage(),
            default => '; TextSMS rejected every dispatch in the batch.',
        };

        $acceptedIds = array_map(
            fn (array $row): string => $row['provider_message_id'] ?? 'not returned',
            array_values(array_filter($unrecorded, fn (array $row): bool => $row['provider_acceptance'] === 'accepted')),
        );

        if ($acceptedIds !== []) {
            $message .= sprintf(
                ' Unrecorded dispatches TextSMS accepted: provider message ids %s.',
                implode(', ', $acceptedIds),
            );
        }

        return $message;
    }

    /**
     * Sending gets one retry only for structured pre-send cURL 6/7 errors.
     * Balance retains its existing independent retry behavior.
     */
    private function http(bool $sending = false): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)
            ->asJson()
            ->acceptJson()
            ->timeout($this->timeoutSeconds);

        if (! $sending) {
            return $request->retry(2, 200, throw: false);
        }

        // A redirect must not introduce an uncounted send before a later
        // connection failure. Received responses are never retried here.
        $transfer = null;

        return $request->withoutRedirecting()->withOptions([
            'on_stats' => static function (TransferStats $stats) use (&$transfer): void {
                $transfer = $stats;
            },
        ])->retry(2, 200, static function (?Throwable $exception) use (&$transfer): bool {
            if (! $exception instanceof ConnectionException) {
                return false;
            }
            // Guzzle 8 exposes cURL evidence through transfer statistics;
            // its exceptions no longer carry the Guzzle 7 handler context.
            if ($transfer instanceof TransferStats) {
                return ! $transfer->hasResponse() && in_array($transfer->getHandlerErrorData(), [6, 7], true);
            }
            $cause = $exception->getPrevious();
            $hasResponse = is_callable([$cause, 'hasResponse']);
            $hasHandlerContext = is_callable([$cause, 'getHandlerContext']);
            if (! $cause instanceof ConnectException && ! ($cause instanceof GuzzleRequestException
                && $hasResponse && ! $cause->hasResponse())) {
                return false;
            }

            return $hasHandlerContext
                && in_array($cause->getHandlerContext()['errno'] ?? null, [6, 7], true);
        }, throw: false);
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
     * A storage failure here happens before TextSMS is contacted. It is
     * reported as a local preparation failure that explicitly says no
     * request was sent.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws SmsProviderException When the row cannot be created.
     */
    private function preInsert(
        string $recipient,
        string $senderId,
        string $body,
        string $clientId,
        ?Model $notifiable,
        array $metadata,
    ): SmsDispatch {
        // Construction is inside the boundary too: filling the model casts
        // `metadata` to JSON, which fails for values such as NAN.
        try {
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
                $row->notifiable()->associate($notifiable);
            }

            $saved = $row->save();
        } catch (Throwable $e) {
            throw $this->preparationFailure($clientId, $e);
        }

        if (! $saved) {
            throw $this->preparationFailure($clientId, new RuntimeException('A model event listener cancelled the SmsDispatch insert.'));
        }

        return $row;
    }

    private function preparationFailure(string $clientId, Throwable $cause): SmsProviderException
    {
        return new SmsProviderException(
            message: "The local dispatch record for client id {$clientId} could not be created; no request was sent to TextSMS.",
            previous: $cause,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return bool False when a model event listener cancelled the update.
     */
    private function markSent(SmsDispatch $dispatch, array $payload): bool
    {
        return $dispatch->update([
            'status' => 'sent',
            'sent_at' => now(),
            'provider_message_id' => $this->providerMessageId($payload),
            'network_id' => isset($payload['networkid']) ? (string) $payload['networkid'] : null,
            'response_code' => (string) ($payload['respose-code'] ?? $payload['response-code'] ?? '200'),
            'response_description' => $this->limitDescription((string) ($payload['response-description'] ?? 'Success')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerMessageId(array $payload): ?string
    {
        return $this->providerString($payload['messageid'] ?? null, 255);
    }

    /**
     * Fit a description into the `response_description` column without
     * splitting a multibyte character. Real column enforcement on
     * MySQL/PostgreSQL is verified at the release gate, not by SQLite.
     */
    private function limitDescription(string $description): string
    {
        return mb_substr($description, 0, self::DESCRIPTION_LIMIT, 'UTF-8');
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
     * @return bool False when a model event listener cancelled the update.
     */
    private function markFailed(
        SmsDispatch $dispatch,
        ?string $code,
        ?string $description,
        ?array $providerErrors = null,
        ?string $parseSource = null,
        ?array $diagnostic = null,
        ?string $providerAcceptance = null,
    ): bool {
        $updates = [
            'status' => 'failed',
            'failed_at' => now(),
            'response_code' => $code,
            'response_description' => $description === null ? null : $this->limitDescription($description),
        ];

        $existingMeta = $dispatch->metadata ?? [];
        $diagnosticsBlock = array_filter([
            'provider' => self::PROVIDER,
            'parse_source' => $parseSource,
            'provider_acceptance' => $providerAcceptance,
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

        return $dispatch->update($updates);
    }

    /**
     * Parse a single-send TextSMS response into a structured outcome
     * regardless of shape.
     *
     * Shape A (per-message) : {"responses": [{"respose-code": 200, ...}]}
     * Shape B (top-level)   : {"response-code": 1003, "response-description": ..., "errors": {...}}
     * Shape C (unparseable) : HTTP error with no JSON / empty body / unknown keys.
     *
     * @return ParsedResponse
     */
    private function parseSendResponse(Response $response, string $clientId): array
    {
        $responses = $response->json('responses');
        if (is_array($responses) && $responses !== []) {
            if (! array_is_list($responses) || count($responses) !== 1) {
                return $this->unknownResponse($response, 'malformed-response', 'A single send did not return exactly one response row.');
            }
            if ($conflict = $this->conflictingResponseEnvelope($response, $responses)) {
                return $conflict;
            }
            $row = $responses[0];
            if (is_array($row) && array_key_exists('clientsmsid', $row)
                && $this->providerString($row['clientsmsid'], 255) !== $clientId) {
                return $this->unknownResponse($response, 'unmatched', 'The single response client reference did not match the request.');
            }

            return $this->parseResponseRow($response, $row);
        }

        return $this->parseGlobalResponse($response);
    }

    /**
     * Parse a bulk-send response into a per-client map plus an optional
     * global-failure outcome (provider rejected the whole batch).
     *
     * Shape C (unparseable) at the bulk endpoint carries the same
     * diagnostic stub as single-send so a batch-wide failure leaves the
     * same trail in `metadata.provider_diagnostics`.
     *
     * @param  list<string>  $clientIds
     * @return ParsedBulkResponse
     */
    private function parseBulkResponse(Response $response, array $clientIds): array
    {
        $responses = $response->json('responses');
        if (! is_array($responses) || $responses === [] || ! array_is_list($responses)) {
            return ['global' => $this->parseGlobalResponse($response), 'perClient' => [], 'anomalies' => []];
        }
        if ($conflict = $this->conflictingResponseEnvelope($response, $responses)) {
            return ['global' => $conflict, 'perClient' => [], 'anomalies' => []];
        }

        $matches = [];
        $anomalies = [];
        $providerIdClaims = [];
        foreach ($responses as $row) {
            // Count claims before filtering: an invalid or unexpected row can
            // still make a supposedly valid sibling's provider ID ambiguous.
            $providerId = is_array($row) ? $this->providerMessageId($row) : null;
            if ($providerId !== null) {
                $providerIdClaims[$providerId] = ($providerIdClaims[$providerId] ?? 0) + 1;
            }
            $clientId = is_array($row) ? $this->providerString($row['clientsmsid'] ?? null, 255) : null;
            if ($clientId === null || ! in_array($clientId, $clientIds, true)) {
                $reason = $clientId === null ? 'malformed-response' : 'unexpected-client-id';
                $anomalies[$reason] = ($anomalies[$reason] ?? 0) + 1;

                continue;
            }
            $matches[$clientId][] = $row;
        }

        $perClient = [];
        foreach ($matches as $clientId => $matched) {
            $parsed = count($matched) === 1
                ? $this->parseResponseRow($response, $matched[0])
                : $this->unknownResponse($response, 'duplicate-client-id', 'More than one response used the same client reference.');
            if ($parsed['source'] === 'responses' && $parsed['code'] === '200'
                && ($providerIdClaims[$this->providerMessageId($parsed['row'])] ?? 0) > 1) {
                $parsed = $this->unknownResponse($response, 'duplicate-provider-id', 'Multiple response entries claimed one provider message ID.', '200');
            }
            $perClient[$clientId] = $parsed;
        }

        return ['global' => null, 'perClient' => $perClient, 'anomalies' => $anomalies];
    }

    /**
     * A successful call envelope can contain mixed per-message outcomes.
     * A non-success envelope cannot coherently claim a different outcome,
     * and error details cannot establish that a success row was accepted.
     *
     * @param  list<mixed>  $rows
     * @return ParsedResponse|null
     */
    private function conflictingResponseEnvelope(Response $response, array $rows): ?array
    {
        $top = $response->json();
        if (! is_array($top)) {
            return null;
        }
        $hasCode = array_key_exists('response-code', $top);
        $code = $this->providerString($top['response-code'] ?? null, 8);
        foreach ($rows as $row) {
            $rowCode = is_array($row) ? $this->providerString($row['respose-code'] ?? $row['response-code'] ?? null, 8) : null;
            if (($hasCode && ($code === null || ($code !== '200' && $rowCode !== $code)))
                || ($rowCode === '200' && isset($top['errors']) && $top['errors'] !== [])) {
                return $this->unknownResponse($response, 'malformed-response', 'The top-level envelope contradicted the per-message response.', $code);
            }
        }

        return null;
    }

    /**
     * Validate fields used for classification and persistence before casting.
     *
     * @return ParsedResponse
     */
    private function parseResponseRow(Response $response, mixed $row): array
    {
        if (! is_array($row)) {
            return $this->unknownResponse($response, 'malformed-response', 'The provider response row was not an object.');
        }
        $rawCode = $row['respose-code'] ?? $row['response-code'] ?? null;
        $code = $this->providerString($rawCode, 8);
        $description = $this->providerString($row['response-description'] ?? null);
        $invalidCode = $rawCode !== null && $code === null;
        $conflictingCodes = isset($row['respose-code'], $row['response-code'])
            && $this->providerString($row['respose-code'], 8) !== $this->providerString($row['response-code'], 8);
        $invalidDescription = isset($row['response-description']) && ! is_string($row['response-description']) && ! is_int($row['response-description']);
        if ($invalidCode || $conflictingCodes || $invalidDescription) {
            return $this->unknownResponse($response, 'malformed-response', 'The response code or description was malformed or contradictory.', $code);
        }
        if ($code === '200') {
            if (! $response->successful()) {
                return $this->unknownResponse($response, 'http-error', 'A success code arrived on HTTP '.$response->status().'.', $code);
            }
            if ($this->providerMessageId($row) === null) {
                return $this->unknownResponse($response, 'invalid-message-id', 'Code 200 arrived without a usable provider message ID.', $code);
            }
            if (isset($row['networkid']) && $this->providerString($row['networkid'], 8) === null) {
                return $this->unknownResponse($response, 'malformed-response', 'The provider network ID was malformed.', $code);
            }
        }
        $row['_http_status'] = $response->status();

        return [
            'source' => 'responses',
            'code' => $code,
            'description' => $description,
            'errors' => null,
            'row' => $row,
        ];
    }

    /** @return ParsedResponse */
    private function parseGlobalResponse(Response $response): array
    {
        $top = $response->json();
        if (is_array($top) && isset($top['responses'])
            && (! is_array($top['responses']) || ! array_is_list($top['responses']))) {
            return $this->unknownResponse(
                $response, 'malformed-response', 'The provider response collection was malformed.',
                $this->providerString($top['response-code'] ?? null, 8),
            );
        }
        if (is_array($top) && array_key_exists('response-code', $top)) {
            $code = $this->providerString($top['response-code'], 8);
            $description = $this->providerString($top['response-description'] ?? null);
            if ($code === null || (isset($top['response-description']) && $description === null && $top['response-description'] !== '')) {
                return $this->unknownResponse($response, 'malformed-response', 'The top-level provider code or description was malformed.', $code);
            }

            return [
                'source' => 'top-level',
                'code' => $code,
                'description' => $description,
                'errors' => isset($top['errors']) && is_array($top['errors']) ? $top['errors'] : null,
                'row' => ['_http_status' => $response->status()],
            ];
        }

        $parsed = $this->unknownResponse($response, 'unparseable', 'Unparseable TextSMS response (HTTP '.$response->status().')');
        $parsed['row']['_raw_body_truncated'] = mb_substr($response->body(), 0, 200, 'UTF-8');

        return $parsed;
    }

    /** @return ParsedResponse */
    private function unknownResponse(Response $response, string $source, string $description, ?string $code = null): array
    {
        return [
            'source' => $source,
            'code' => $code,
            'description' => $description,
            'errors' => null,
            'row' => ['_http_status' => $response->status()],
        ];
    }

    private function providerString(mixed $value, ?int $maxLength = null): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = (string) $value;

        return trim($value) === '' || ($maxLength !== null && mb_strlen($value, 'UTF-8') > $maxLength) ? null : $value;
    }
}
