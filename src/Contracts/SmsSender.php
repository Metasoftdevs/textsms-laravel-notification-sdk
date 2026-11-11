<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Outbound SMS sender contract — the provider-agnostic surface every concrete
 * gateway implements.
 *
 * Three methods, deliberately narrow:
 *
 *  - `send()`     dispatch one message, persist one {@see SmsDispatch}.
 *  - `sendBulk()` dispatch up to a provider-imposed batch limit in a single
 *                 HTTP call; persist one row per message; reconcile each row
 *                 against the per-message response by `client_message_id`.
 *  - `balance()`  return the provider's account balance as a free-form array
 *                 (for ops dashboards and health checks).
 *
 * Delivery-report ingestion is intentionally **NOT** on this interface — it
 * is a webhook-fed inbound concern handled separately by
 * {@see SmsDeliveryReportProcessor}. Most concrete gateways implement both
 * interfaces; the split keeps test doubles narrow and keeps "send-only" or
 * "receive-only" deployments possible.
 *
 * Benchmark: derived from `App\Services\Sms\Contracts\SmsGateway` in QPIMS,
 * with the inbound DLR method extracted to {@see SmsDeliveryReportProcessor}
 * per Reflection §4.2.
 */
interface SmsSender
{
    /**
     * Persist + dispatch a single SMS through the configured provider.
     *
     * @param  string  $recipient  Raw phone number; the implementation must
     *                             normalise it via {@see PhoneNumberNormalizer}
     *                             before any provider call.
     * @param  SmsMessage  $message  Body, optional sender override, optional
     *                               client reference, free-form metadata.
     * @param  Model|null  $notifiable  Optional polymorphic owner persisted on
     *                                  the row (`notifiable_type`, `notifiable_id`).
     * @return SmsDispatch The persisted row reflecting the outcome (status
     *                     `sent` on success, `failed` rows are persisted but
     *                     the method also throws {@see SmsProviderException}).
     *
     * @throws SmsDispatchException Bad input: invalid phone, empty body.
     * @throws SmsProviderException Provider refusal, unknown outcome, or local preparation/recording failure; inspect the message before retrying.
     */
    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch;

    /**
     * Persist + dispatch up to the provider's batch limit in one HTTP call.
     *
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     * @return Collection<int, SmsDispatch> Rows in input order.
     *
     * @throws SmsDispatchException When `count($items)` exceeds the provider limit or items are malformed.
     * @throws SmsProviderException Whole-response failure, unknown outcome, or local preparation/recording failure. Per-item failures return rows; failed does not imply safe resend.
     */
    public function sendBulk(array $items): Collection;

    /**
     * Provider account balance (free-form payload — exact shape is provider-specific).
     *
     * @return array<string, mixed>
     *
     * @throws SmsProviderException
     */
    public function balance(): array;
}
