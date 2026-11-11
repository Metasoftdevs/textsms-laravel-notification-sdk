<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers\TextSmsCallbackController;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Informational delivery-report recorder contract.
 *
 * Implementations are called by the webhook controller
 * ({@see TextSmsCallbackController})
 * each time the provider posts a DLR. They are responsible for:
 *
 *  1. Resolving the affected {@see SmsDispatch} by `provider_message_id`.
 *  2. Storing the sanitized payload in `dlr_payload` only, without changing
 *     submission state or timestamps, or invoking model observers, delivery
 *     events or application actions. No delivery outcome is classified.
 *  3. Propagating storage failures rather than acknowledging a lost write.
 *
 * Return value:
 *  - The affected {@see SmsDispatch} when one was matched.
 *  - `null` when the payload referenced a `messageid` no row matched
 *    (the SDK acknowledges unmatched ids with HTTP 200). Provider retry
 *    behavior remains unverified. The implementation logs the miss.
 *
 * Input precondition:
 *
 *  - `$payload` must not contain authentication secrets. Implementations may
 *    persist it (`dlr_payload`) or log it. The bundled webhook controller removes the `token` key and
 *    rejects malformed `messageid` / `status` shapes before calling this
 *    method. Code that calls an implementation directly (a poller, a queued
 *    job, a test) is responsible for the same sanitisation.
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
     * Record a delivery-report payload without application side effects.
     *
     * @param  array<string, mixed>  $payload  The decoded provider payload, already stripped of
     *                                         authentication secrets (see the interface precondition).
     * @return SmsDispatch|null The affected row or null if no row matched.
     */
    public function recordDeliveryReport(array $payload): ?SmsDispatch;
}
