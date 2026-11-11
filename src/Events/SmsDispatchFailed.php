<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Fired after an unsuccessful outbound attempt is recorded as failed.
 * This includes confirmed refusal and unknown acceptance (transport,
 * unparseable or incomplete/ambiguous response correlation). The event
 * does not prove the SMS was never sent; inspect provider_diagnostics.
 *
 * The event carries the same structured fields the gateway persists into
 * `sms_dispatches.metadata.provider_diagnostics`:
 *
 *  - `providerCode`         the provider's error code (e.g. `1004`, `1006`)
 *  - `providerDescription`  human-readable description from the provider
 *  - `providerErrors`       structured `errors` map from validation
 *                           responses (HTTP 422 shape from TextSMS)
 *  - `parseSource`          which response-shape branch produced the row
 *                           (normal response shape or anomaly source)
 *
 * Listeners can use these to:
 *
 *  - Page on-call for `1004` low credits.
 *  - Open a ticket for `1006` invalid credentials.
 *  - Flag a domain row for investigation before any resend.
 *  - Emit a Sentry breadcrumb with the structured `errors` map.
 *
 * Benchmark: replaces the inline `ActivityLogger::log('SMS Failed', ...)`
 * call in QPIMS's `TextSmsGateway` per Reflection §3.1 and §3.2.
 */
final class SmsDispatchFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $providerErrors  Structured `errors` map from validation responses.
     */
    public function __construct(
        public readonly SmsDispatch $dispatch,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerDescription = null,
        public readonly ?array $providerErrors = null,
        public readonly ?string $parseSource = null,
    ) {}
}
