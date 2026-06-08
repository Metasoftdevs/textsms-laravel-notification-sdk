<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers\TextSmsCallbackController;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Inbound delivery-report consumer contract.
 *
 * Implementations are called by the webhook controller
 * ({@see TextSmsCallbackController})
 * each time the provider posts a DLR. They are responsible for:
 *
 *  1. Resolving the affected {@see SmsDispatch} by `provider_message_id`.
 *  2. Classifying the raw status string into one of `delivered`, `failed`,
 *     or `unknown`.
 *  3. Updating the dispatch row's `status`, `delivered_at`/`failed_at`, and
 *     `dlr_payload`.
 *  4. Dispatching the
 *     {@see SmsDeliveryReported}
 *     event so consumer-side listeners can reconcile their own domain state
 *     (receipts, orders, OTP bookkeeping, etc.).
 *
 * Return value:
 *  - The affected {@see SmsDispatch} when one was matched.
 *  - `null` when the payload referenced a `messageid` no row matched
 *    (TextSMS occasionally retries against stale ids). Returning null lets
 *    the controller respond with HTTP 200 so the provider stops retrying;
 *    the implementation is responsible for logging the miss internally.
 *
 * Split from {@see SmsSender} on purpose: outbound and inbound have different
 * lifecycles, different actors, and different testing surfaces.
 *
 * Benchmark: extracted from `App\Services\Sms\Contracts\SmsGateway::recordDeliveryReport()`
 * in QPIMS per Reflection §4.2.
 */
interface SmsDeliveryReportProcessor
{
    /**
     * Reconcile a delivery-report payload posted by the provider's webhook.
     *
     * @param  array<string, mixed>  $payload  The raw decoded JSON body.
     * @return SmsDispatch|null The affected row or null if no row matched.
     */
    public function recordDeliveryReport(array $payload): ?SmsDispatch;
}
