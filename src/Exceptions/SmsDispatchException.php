<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions;

use RuntimeException;

/**
 * Thrown when an SMS cannot be dispatched for **client-side** reasons:
 * a phone number that cannot be normalised, an empty body, or a bulk
 * payload that exceeds the provider's per-call limit.
 *
 * This is distinct from {@see SmsProviderException}, which is raised when
 * the provider itself rejects a dispatch (low credits, invalid sender ID,
 * malformed credentials, transport failure). Splitting the two lets ops
 * dashboards filter outage signal from code-quality signal:
 *
 *   - {@see SmsDispatchException}  → fix the caller.
 *   - {@see SmsProviderException}  → fix the provider integration / credit /
 *                                    sender registration.
 *
 * Benchmark: ported verbatim from
 * `app/Services/Sms/Exceptions/SmsDispatchException.php` in QPIMS.
 */
class SmsDispatchException extends RuntimeException {}
