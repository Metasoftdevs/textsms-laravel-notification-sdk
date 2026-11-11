<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when the upstream SMS provider returns an error or is unreachable,
 * and when a dispatch's local record cannot be created or updated.
 *
 * Local record failures keep this type so the notification channel's
 * existing catch still applies. `getPrevious()` holds the storage
 * exception, and the message tells the cases apart:
 *
 *  - **Local preparation failure** ("…could not be created; no request was
 *    sent to TextSMS"): the provider was never contacted for this message,
 *    so resending it cannot create a duplicate.
 *  - **Local recording failure after the provider call** ("…recording …
 *    locally … failed"): the `sms_dispatches` row may still say `pending`
 *    (or may already hold the new state). The message states what is known:
 *    TextSMS **accepted** the SMS (it was sent — do **not** resend it), TextSMS
 *    gave **no usable outcome** (it **may** have been sent — do not resend it
 *    automatically), or TextSMS **rejected** it with a documented
 *    send-rejection code (an unrecognised code counts as no usable outcome). Bulk
 *    messages say whether resending the batch as a whole is unsafe.
 *    Recovery identifiers (dispatch ULID, client id, provider message id,
 *    `provider_acceptance`) are logged at error level as "TextSmsGateway:
 *    dispatch result could not be recorded locally", best effort: a failing
 *    logger does not prevent this exception. Credentials, recipients, and
 *    message bodies are never logged.
 *
 * No lifecycle event fires for a `send()` / `sendBulk()` result that could
 * not be recorded.
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
