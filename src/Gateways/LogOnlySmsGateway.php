<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\LoggerInterface;

/**
 * No-network gateway for local development and CI.
 *
 * Persists `sms_dispatches` rows with `status=sent` exactly like a real
 * provider, fires {@see SmsDispatchAccepted}, and writes the dispatched
 * body to the PSR-3 log so you can see what would have gone out. **No HTTP
 * request is ever made.**
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

    public function __construct(
        private readonly PhoneNumberNormalizer $normalizer,
        private readonly LoggerInterface $logger,
        private readonly string $defaultSenderId = 'LOG',
    ) {}

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        $normalised = $this->normalizer->normalize($recipient)
            ?? throw new SmsDispatchException("Invalid recipient phone number: {$recipient}");

        $body = trim($message->body);

        if ($body === '') {
            throw new SmsDispatchException('SMS body cannot be empty.');
        }

        $row = new SmsDispatch([
            'provider' => self::PROVIDER,
            'recipient' => $normalised,
            'sender_id' => $message->senderId ?? $this->defaultSenderId,
            'body' => $body,
            'client_message_id' => $message->clientReference,
            'status' => 'sent',
            'attempt_count' => 1,
            'sent_at' => now(),
            'response_code' => '200',
            'response_description' => 'log-only',
            'metadata' => $message->metadata,
        ]);

        if ($notifiable !== null) {
            $row->notifiable_type = $notifiable::class;
            $row->notifiable_id = $notifiable->getKey();
        }

        $row->save();

        $this->logger->info('LogOnlySmsGateway: SMS (not sent)', [
            'recipient' => $normalised,
            'sender_id' => $row->sender_id,
            'body' => $body,
            'dispatch_ulid' => $row->ulid,
        ]);

        SmsDispatchAccepted::dispatch($row);

        return $row;
    }

    /**
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     */
    public function sendBulk(array $items): Collection
    {
        return collect($items)->map(
            fn (array $item): SmsDispatch => $this->send(
                $item['recipient'],
                $item['message'],
                $item['notifiable'] ?? null,
            ),
        );
    }

    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        // The log-only gateway has no upstream provider to receive DLRs
        // from. This implementation is a noop so the contract still
        // resolves cleanly if a webhook hits it in a misconfigured env.
        $this->logger->info('LogOnlySmsGateway: DLR ignored', ['payload' => $payload]);

        return null;
    }

    public function balance(): array
    {
        return ['provider' => self::PROVIDER, 'credit' => 'unlimited (log-only)'];
    }
}
