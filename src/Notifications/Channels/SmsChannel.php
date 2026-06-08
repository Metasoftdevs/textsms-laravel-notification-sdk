<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Custom Laravel notification channel that bridges Notifications → SmsSender.
 *
 * Registered as the `'sms'` driver in
 * {@see TextSmsNotificationServiceProvider::boot()}.
 * Once registered, notifications can return the simple string from `via()`:
 *
 *   ```
 *   public function via(object $notifiable): array
 *   {
 *       return ['mail', 'sms'];
 *   }
 *   ```
 *
 * Flow:
 *
 *  1. Skip if the notification has no `toSms()` method.
 *  2. Skip if `toSms()` does not return an {@see SmsMessage}.
 *  3. Resolve recipient via `$notifiable->routeNotificationFor('sms', ...)`.
 *     Skip if the notifiable returns null or empty (e.g. user with no phone).
 *  4. Invoke the configured {@see SmsSender}.
 *  5. **Catch** {@see SmsDispatchException} and {@see SmsProviderException}
 *     and log them. Do NOT rethrow — see the rationale below.
 *
 * Return value:
 *  - The persisted {@see SmsDispatch} on a successful send. Laravel
 *    forwards this to the notification's `afterSending()` callback as the
 *    third argument, so caller-side denormalisation (e.g. updating a
 *    receipt's `sms_status`) can read provider data without a separate
 *    query.
 *  - `null` on skip or caught failure.
 *
 * **Why the channel does NOT rethrow gateway exceptions:** letting either
 * exception escape would mark the entire Laravel notification as failed
 * and trigger `failed()` on every channel — including `mail`, which may
 * have already succeeded. The gateway has already persisted a `failed`
 * row and fired {@see SmsDispatchFailed},
 * which are the source of truth.
 *
 * Benchmark: ported verbatim from `app/Notifications/Channels/SmsChannel.php`
 * in QPIMS, with the dependency switched from the merged `SmsGateway`
 * interface to the narrower {@see SmsSender}.
 */
class SmsChannel
{
    public function __construct(
        private readonly SmsSender $sender,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(object $notifiable, Notification $notification): ?SmsDispatch
    {
        if (! method_exists($notification, 'toSms')) {
            return null;
        }

        $message = $notification->toSms($notifiable);

        if (! $message instanceof SmsMessage) {
            return null;
        }

        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return null;
        }

        $recipient = $notifiable->routeNotificationFor('sms', $notification);

        if (! is_string($recipient) || $recipient === '') {
            return null;
        }

        try {
            return $this->sender->send(
                recipient: $recipient,
                message: $message,
                notifiable: $notifiable instanceof Model ? $notifiable : null,
            );
        } catch (SmsDispatchException|SmsProviderException $e) {
            $this->logger->warning('SmsChannel: dispatch failed', [
                'error' => $e->getMessage(),
                'notification' => $notification::class,
                'notifiable_type' => $notifiable instanceof Model ? $notifiable::class : null,
                'notifiable_id' => $notifiable instanceof Model ? $notifiable->getKey() : null,
            ]);

            return null;
        }
    }
}
