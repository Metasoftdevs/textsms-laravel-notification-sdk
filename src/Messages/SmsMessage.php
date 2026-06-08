<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages;

/**
 * Immutable value object returned from `Notification::toSms($notifiable)`.
 *
 * Mirrors the shape of Laravel's MailMessage in role: it carries content
 * the sender wants delivered, but NOT the recipient — recipient resolution
 * lives in the channel via `routeNotificationForSms()` on the notifiable.
 * This separation lets the same SmsMessage be sent to multiple notifiables
 * without rewriting it.
 *
 * Example:
 *
 *   ```
 *   public function toSms(object $notifiable): SmsMessage
 *   {
 *       return new SmsMessage(
 *           body: __('sms.receipt', ['ref' => $this->receipt->number]),
 *           clientReference: 'receipt-' . $this->receipt->id,
 *           metadata: ['receipt_id' => $this->receipt->id],
 *       );
 *   }
 *   ```
 *
 * Fields:
 *
 *  - `body`            The exact text the provider will dispatch. Trimmed
 *                      and validated downstream by the gateway.
 *  - `senderId`        Per-message override of the configured default sender
 *                      (e.g. `services.textsms.default_sender_id`). Null means
 *                      "use the default".
 *  - `clientReference` Round-trips through TextSMS as `clientsmsid`. Useful
 *                      for matching bulk responses back to rows and for
 *                      idempotency checks. Null means "let the gateway
 *                      generate one".
 *  - `metadata`        Free-form caller context, persisted on the
 *                      `sms_dispatches.metadata` JSON column. Reserved
 *                      keys: `provider_diagnostics` (gateway-owned, do not
 *                      set from a notification).
 *
 * Benchmark: ported verbatim from
 * `app/Services/Sms/Messages/SmsMessage.php` in QPIMS.
 */
final class SmsMessage
{
    /**
     * @param  array<string, mixed>  $metadata  Free-form caller context, persisted on sms_dispatches.metadata.
     */
    public function __construct(
        public readonly string $body,
        public readonly ?string $senderId = null,
        public readonly ?string $clientReference = null,
        public readonly array $metadata = [],
    ) {}
}
