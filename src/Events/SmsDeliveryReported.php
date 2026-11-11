<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Legacy delivery event retained for source and queued-event compatibility.
 *
 * The default gateway no longer emits this event: callbacks are stored as
 * information only. Existing listeners will not receive new callbacks.
 * If application code explicitly creates this event, it owns the meanings
 * of deliveryStatus/rawStatus and must supply a sanitized payload.
 * On queue restoration, dispatch is the current database model while the
 * scalar properties and payload retain their originally supplied values.
 */
final class SmsDeliveryReported
{
    use Dispatchable, SerializesModels;

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
