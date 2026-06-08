<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

/**
 * Example SMS-only notification suitable for OTP / 2FA flows.
 *
 * Demonstrates:
 *
 *  - `via()` returning `['sms']` only (no mail fallback — OTPs are
 *    explicitly time-sensitive transport-pinned).
 *  - **Not** using `ShouldQueue` because OTPs need to arrive within
 *    seconds, not whenever the next queue worker picks the job up.
 *    (If you're sure your queue is millisecond-fresh, add it back.)
 *  - Short, locale-aware body via `__()` so consumers can translate.
 *  - `clientReference` includes the user id so the dispatch row can be
 *    correlated to the OTP attempt without searching the body text.
 *
 * Adapt for password-reset codes, login-confirmation codes, etc.
 */
final class OtpNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly int $userId,
        public readonly int $minutesValid = 5,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: __('sms.otp_body', [
                'code' => $this->code,
                'minutes' => $this->minutesValid,
            ]),
            clientReference: 'otp-user-'.$this->userId.'-'.now()->timestamp,
            metadata: [
                'kind' => 'otp',
                'user_id' => $this->userId,
                'valid_for_minutes' => $this->minutesValid,
            ],
        );
    }
}
