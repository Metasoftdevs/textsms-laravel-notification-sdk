<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;

/**
 * Composite normaliser that tries each child in order; the first non-null
 * result wins.
 *
 * Intended for consumers serving multiple regions. The package ships only
 * {@see KenyanE164Normalizer} out of the box — other regions
 * (Ugandan +256, Tanzanian +255, Nigerian +234, etc.) are documented in
 * `docs/extending-normalizers.md` and consumers can compose them here:
 *
 *   ```
 *   // AppServiceProvider::register()
 *   use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
 *   use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;
 *   use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
 *
 *   $this->app->bind(PhoneNumberNormalizer::class, fn () => new ChainedNormalizer([
 *       new KenyanE164Normalizer(),
 *       new UgandanE164Normalizer(),   // consumer-provided
 *       new TanzanianE164Normalizer(), // consumer-provided
 *   ]));
 *   ```
 *
 * `isValid()` accepts a candidate if **any** child accepts it. This mirrors
 * `normalize()`'s "first non-null wins" semantic.
 *
 * Why a chain rather than a registry:
 *  - Ordering is explicit and visible in calling code.
 *  - No magic country-code lookup table to maintain.
 *  - Trivial to test: append a new normaliser to the array and assert.
 */
final class ChainedNormalizer implements PhoneNumberNormalizer
{
    /**
     * @param  list<PhoneNumberNormalizer>  $normalizers  Tried in order; first non-null wins.
     */
    public function __construct(
        private readonly array $normalizers,
    ) {}

    public function normalize(?string $raw): ?string
    {
        foreach ($this->normalizers as $normalizer) {
            $result = $normalizer->normalize($raw);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function isValid(?string $candidate): bool
    {
        foreach ($this->normalizers as $normalizer) {
            if ($normalizer->isValid($candidate)) {
                return true;
            }
        }

        return false;
    }
}
