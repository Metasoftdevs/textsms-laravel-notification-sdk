<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;

/**
 * Default phone-number normaliser shipped with the package.
 *
 * Accepts the five common Kenyan input shapes and reduces them to the
 * canonical E.164 form `254XXXXXXXXX` (no leading `+`):
 *
 *  - `0712345678`         → `254712345678`  (drop leading 0, prepend 254)
 *  - `+254712345678`      → `254712345678`  (strip non-digits)
 *  - `254712345678`       → `254712345678`  (pass through)
 *  - `712345678`          → `254712345678`  (9-digit form without country/leading 0)
 *  - `+254 712-345 678`   → `254712345678`  (whitespace, dashes, parens stripped)
 *
 * Anything else returns `null`. The strict validator
 * {@see KenyanE164Normalizer::isValid()}
 * insists on the exact `254(7|1)XXXXXXXX` shape with no leading `+`.
 *
 * Valid mobile prefixes:
 *  - `7` → Safaricom and others (legacy + newly issued 7xx ranges).
 *  - `1` → Airtel / Telkom (legacy 1xx ranges).
 *
 * Landlines (`0202xxxxxx` and similar) and non-Kenyan country codes
 * deliberately fail. Consumers serving additional regions should compose
 * this normaliser inside
 * {@see ChainedNormalizer}
 * along with their region-specific implementations.
 *
 * Benchmark: ported verbatim from `app/Services/Sms/Support/PhoneNumber.php`
 * in QPIMS, promoted from a static helper to a `PhoneNumberNormalizer`
 * implementation per Reflection §3.3.
 */
final class KenyanE164Normalizer implements PhoneNumberNormalizer
{
    /** {@inheritDoc} */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        // 0712345678 → 254712345678 (drop leading 0, prepend 254)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254'.substr($digits, 1);
        }

        // 712345678 → 254712345678 (9-digit form without country/leading 0)
        if (strlen($digits) === 9 && (str_starts_with($digits, '7') || str_starts_with($digits, '1'))) {
            $digits = '254'.$digits;
        }

        return $this->isValid($digits) ? $digits : null;
    }

    /** {@inheritDoc} */
    public function isValid(?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        return (bool) preg_match('/^254(7|1)\d{8}$/', $candidate);
    }
}
