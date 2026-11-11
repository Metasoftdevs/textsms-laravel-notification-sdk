<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use JsonException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * No-network gateway for local development and CI.
 *
 * Persists `sms_dispatches` rows with `status=sent` exactly like a real
 * provider, fires {@see SmsDispatchAccepted}, and writes the dispatched
 * body to the PSR-3 log so you can see what would have gone out. **No HTTP
 * request is ever made.**
 * Complete batches are validated and saved atomically on the configured
 * dispatch connection before logging or publication. Storage failures
 * propagate; immediate listener, reporter and logger failures are contained.
 *
 * Bind in your service provider for environments where you want the full
 * notification pipeline to run without spending real credits:
 *
 *   ```
 *   // config/textsms.php
 *   'default_sender' => env('APP_ENV') === 'local'
 *       ? \MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\LogOnlySmsGateway::class
 *       : \MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway::class,
 *   ```
 *
 * Proves the abstraction: a second implementation of {@see SmsSender} sits
 * here, validating that nothing outside the gateway adapter knows it is
 * talking to TextSMS (Reflection §4.1).
 */
final class LogOnlySmsGateway implements SmsDeliveryReportProcessor, SmsSender
{
    public const PROVIDER = 'log-only';

    private const BULK_LIMIT = 20;

    /** Configure local recipient validation and logging; no provider requests are made. */
    public function __construct(
        private readonly PhoneNumberNormalizer $normalizer,
        private readonly LoggerInterface $logger,
        private readonly string $defaultSenderId = 'LOG',
    ) {}

    /** Persist and log a simulated accepted dispatch, then notify consumer listeners. */
    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        return $this->sendBulk([['recipient' => $recipient, 'message' => $message, 'notifiable' => $notifiable]])->firstOrFail();
    }

    /**
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     */
    public function sendBulk(array $items): Collection
    {
        if (count($items) > self::BULK_LIMIT) {
            throw new SmsDispatchException('LogOnlySmsGateway accepts at most '.self::BULK_LIMIT.' messages per batch.');
        }
        if ($items === []) {
            return collect();
        }
        $prepared = $this->prepareItems($items);
        $rows = (new SmsDispatch)->getConnection()->transaction(function () use ($prepared): array {
            $rows = [];
            foreach ($prepared as $item) {
                $row = new SmsDispatch([
                    'provider' => self::PROVIDER,
                    'recipient' => $item['recipient'],
                    'sender_id' => $item['message']->senderId ?? $this->defaultSenderId,
                    'body' => $item['body'],
                    'client_message_id' => $item['client_id'],
                    'status' => 'sent',
                    'attempt_count' => 1,
                    'sent_at' => now(),
                    'response_code' => '200',
                    'response_description' => 'log-only',
                    'metadata' => $item['message']->metadata,
                ]);
                if ($item['notifiable'] !== null) {
                    $row->notifiable()->associate($item['notifiable']);
                }
                if (! $row->save()) {
                    throw new RuntimeException('A model event cancelled the log-only dispatch save.');
                }
                $rows[] = $row;
            }

            return $rows;
        });

        foreach ($rows as $row) {
            rescue(fn () => $this->logger->info('LogOnlySmsGateway: SMS (not sent)', [
                'recipient' => $row->recipient,
                'sender_id' => $row->sender_id,
                'body' => $row->body,
                'dispatch_ulid' => $row->ulid,
            ]), report: false);
            rescue(fn () => event(new SmsDispatchAccepted($row)), fn (Throwable $error) => rescue(fn () => report($error), report: false), report: false);
        }

        return collect($rows);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{recipient: string, body: string, client_id: string, message: SmsMessage, notifiable: ?Model}>
     */
    private function prepareItems(array $items): array
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
                $recipient = $this->normalizer->normalize($item['recipient'])
                    ?? throw new SmsDispatchException('Invalid recipient phone number.');
                $body = trim($message->body);
                if ($body === '') {
                    throw new SmsDispatchException('SMS body cannot be empty.');
                }
                $clientId = $message->clientReference ?? (string) Str::ulid()->toBase32();
                if (trim($clientId) === '' || mb_strlen($clientId, 'UTF-8') > 255 || in_array($clientId, $clientIds, true)) {
                    throw new SmsDispatchException('client reference must be non-blank, at most 255 characters and unique in the batch.');
                }
                json_encode([$recipient, $body, $message->senderId ?? $this->defaultSenderId, $clientId, $message->metadata], JSON_THROW_ON_ERROR);
                $clientIds[] = $clientId;
                $prepared[] = ['recipient' => $recipient, 'body' => $body, 'client_id' => $clientId, 'message' => $message, 'notifiable' => $notifiable];
            } catch (SmsDispatchException|JsonException $error) {
                throw new SmsDispatchException("Bulk item {$index}: ".$error->getMessage(), previous: $error);
            }
        }

        return $prepared;
    }

    /** Log and ignore the sanitized payload; this sender has no provider delivery reports. */
    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        // The log-only gateway has no upstream provider to receive DLRs
        // from. This implementation is a noop so the contract still
        // resolves cleanly if a webhook hits it in a misconfigured env.
        rescue(fn () => $this->logger->info('LogOnlySmsGateway: DLR ignored', ['payload' => $payload]), report: false);

        return null;
    }

    /** Return a synthetic unlimited balance for local operation. */
    public function balance(): array
    {
        return ['provider' => self::PROVIDER, 'credit' => 'unlimited (log-only)'];
    }
}
