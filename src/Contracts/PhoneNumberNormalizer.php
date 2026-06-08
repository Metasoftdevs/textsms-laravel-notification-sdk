<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;

/**
 * Recipient phone-number normaliser used at the gateway boundary.
 *
 * Every {@see SmsSender}
 * implementation invokes a normaliser before any HTTP call so the rest of
 * the module deals in exactly one canonical shape (E.164 without the
 * leading `+`, for example `254712345678`).
 *
 * The package ships
 * {@see KenyanE164Normalizer}
 * by default. Consumers serving multiple regions can bind
 * {@see ChainedNormalizer}
 * with an ordered list of single-region implementations.
 *
 * Custom implementations must satisfy:
 *
 *  - `normalize()` returns null whenever the input cannot be coerced to the
 *    target form. Callers decide whether null is a hard error or a silent
 *    skip (the bundled `SmsChannel` skips silently — same shape as a user
 *    with no `email` in mail routing).
 *  - `isValid()` is a strict check on an already-normalised string. It
 *    must NOT perform any normalisation itself.
 *
 * Benchmark: derived from the static helper at
 * `app/Services/Sms/Support/PhoneNumber.php` in QPIMS — promoted from a
 * static utility to an interface so consumers can register their own.
 */
interface PhoneNumberNormalizer
{
    /**
     * Coerce a raw user-supplied phone number into the canonical E.164 form
     * (without the leading `+`). Return null when normalisation fails.
     */
    public function normalize(?string $raw): ?string;

    /**
     * Strictly validate an already-normalised candidate against the
     * normaliser's region rules. Must not perform additional normalisation.
     */
    public function isValid(?string $candidate): bool;
}
