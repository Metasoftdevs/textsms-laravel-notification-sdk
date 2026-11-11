<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Fired by the gateway the moment the provider accepts a dispatch — i.e.
 * the row has just transitioned from `pending` to `sent` and carries a
 * `provider_message_id`.
 *
 * Subscribe a listener to capture audit events, push notifications to a
 * dashboard, or update caller-side denormalised columns (e.g. a receipt's
 * `sms_sent_at`).
 *
 * The package itself does NOT subscribe any listener. Audit and
 * domain-reconciliation belong to the host application.
 *
 * Benchmark: replaces the inline `ActivityLogger::log('SMS Sent', ...)`
 * call in QPIMS's `TextSmsGateway` per Reflection §3.1 and §3.2.
 */
final class SmsDispatchAccepted
{
    use Dispatchable, SerializesModels;

    /** Attach the recorded dispatch for synchronous or queued consumer listeners. */
    public function __construct(
        public readonly SmsDispatch $dispatch,
    ) {}
}
