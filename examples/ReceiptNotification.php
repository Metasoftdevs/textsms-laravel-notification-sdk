<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

/**
 * Example end-to-end notification that ships a receipt via mail and SMS.
 *
 * Demonstrates:
 *
 *  - `via()` returning `['mail', 'sms']` so both channels fire.
 *  - `toMail()` for the rich HTML/text path.
 *  - `toSms()` returning an `SmsMessage` DTO.
 *  - Per-message `clientReference` so the persisted `sms_dispatches.client_message_id`
 *    can be matched back to the receipt without a join.
 *  - `metadata.receipt_id` so a domain-side listener subscribing to
 *    `SmsDeliveryReported` can reconcile `receipts.sms_delivered_at`
 *    when the DLR lands.
 *  - `ShouldQueue` so SMTP + SMS dispatch run in the worker rather than
 *    the request lifecycle.
 *
 * Mirror this shape for your own domain notifications (orders, KYC
 * approvals, contract signatures, etc.).
 */
final class ReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $receiptNumber,
        public readonly float $amount,
        public readonly int $receiptId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'sms'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.receipt_subject', ['number' => $this->receiptNumber]))
            ->greeting(__('mail.receipt_greeting', ['name' => $notifiable->name]))
            ->line(__('mail.receipt_amount_received', ['amount' => $this->amount]))
            ->action(__('mail.receipt_view_action'), url('/receipts/'.$this->receiptId));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: __('sms.receipt_body', [
                'number' => $this->receiptNumber,
                'amount' => number_format($this->amount, 2),
            ]),
            clientReference: 'receipt-'.$this->receiptId,
            metadata: [
                'receipt_id' => $this->receiptId,
                'receipt_number' => $this->receiptNumber,
            ],
        );
    }
}
