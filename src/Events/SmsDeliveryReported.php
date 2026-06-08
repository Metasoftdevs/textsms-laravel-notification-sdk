<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Fired by the gateway each time the provider's DLR webhook posts a
 * delivery report against a previously dispatched message.
 *
 * Classification semantics (`deliveryStatus`):
 *
 *  - `delivered`  the provider reports the message reached the handset.
 *  - `failed`     the provider reports a terminal failure (undeliverable,
 *                 expired, rejected, etc.).
 *  - `unknown`    an interim or unrecognised state (e.g. `enroute`,
 *                 `buffered`). The dispatch row still gets `dlr_payload`
 *                 stored but the `status` column is left untouched; the
 *                 event still fires so listeners can observe transitions.
 *
 * `rawStatus` is the lowercased status string straight from the provider
 * payload, in case a listener needs more fine-grained branching than the
 * normalised classification provides.
 *
 * `payload` is the full decoded JSON the provider posted.
 *
 * **This is the event a consumer subscribes to in order to reconcile its
 * own domain.** For example, QPIMS subscribes
 * `App\Listeners\SyncReceiptFromSmsDelivery` here to update
 * `receipts.sms_delivered_at` / `sms_status` / `resend_required` — work
 * that used to live directly inside the gateway.
 *
 * Benchmark: replaces `TextSmsGateway::syncReceiptFromDeliveryReport()` in
 * QPIMS per Reflection §3.1.
 */
final class SmsDeliveryReported
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  Raw decoded JSON the provider posted.
     */
    public function __construct(
        public readonly SmsDispatch $dispatch,
        public readonly string $deliveryStatus,
        public readonly string $rawStatus,
        public readonly array $payload,
    ) {}
}
