<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the upstream SMS provider returns an error or is unreachable.
 *
 * The provider's response code (e.g. TextSMS `1004` low credits, `1006`
 * invalid credentials, `1003` validation error) is preserved on the
 * exception so callers can branch on it without re-parsing the response.
 *
 * `providerErrors` carries the structured `errors` map from
 * validation-style responses (TextSMS HTTP 422), for example:
 *
 *   ```
 *   ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']]
 *   ```
 *
 * It is `null` for per-message error codes that come back inside
 * `responses[]` without nested validation data.
 *
 * Benchmark: ported verbatim from
 * `app/Services/Sms/Exceptions/SmsProviderException.php` in QPIMS
 * (post-`07-validation-error-parsing.md` shape).
 */
class SmsProviderException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $providerErrors  Structured `errors` map from validation responses.
     */
    public function __construct(
        string $message,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerDescription = null,
        public readonly ?array $providerErrors = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
