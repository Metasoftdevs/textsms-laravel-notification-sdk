# Adding a phone-number region

The SDK ships only `KenyanE164Normalizer` (Safaricom 7xx + Airtel/Telkom 1xx).
Two patterns to add more regions.

## Pattern A: a single non-Kenyan region

Write a `PhoneNumberNormalizer` implementation for your region and rebind:

```php
<?php

namespace App\Sms;

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;

final class UgandanE164Normalizer implements PhoneNumberNormalizer
{
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '256'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '256'.$digits;
        }

        return $this->isValid($digits) ? $digits : null;
    }

    public function isValid(?string $candidate): bool
    {
        return $candidate !== null
            && (bool) preg_match('/^2567\d{8}$/', $candidate);
    }
}
```

Bind it in `AppServiceProvider`:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use App\Sms\UgandanE164Normalizer;

$this->app->bind(PhoneNumberNormalizer::class, UgandanE164Normalizer::class);
```

## Pattern B: multiple regions composed

Wrap a chain so the first matching region wins:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use App\Sms\UgandanE164Normalizer;
use App\Sms\TanzanianE164Normalizer;

$this->app->bind(PhoneNumberNormalizer::class, fn () => new ChainedNormalizer([
    new KenyanE164Normalizer(),
    new UgandanE164Normalizer(),
    new TanzanianE164Normalizer(),
]));
```

`ChainedNormalizer::normalize()` returns the first non-null result.
`ChainedNormalizer::isValid()` returns true if any child accepts the
candidate.

## Why a chain rather than a registry

- **Ordering is explicit and visible** in the calling code — no magic
  country-code lookup table to maintain.
- **No coupling to a country-code database** — each normaliser owns its
  validation rules.
- **Trivial to test** — append a new normaliser to the array and assert.

## Testing your normaliser

Mirror the `KenyanE164NormalizerTest` shape from the package's test
suite. The dataset-driven Pest pattern keeps the regression set
declarative:

```php
it('normalises Ugandan numbers to E.164', function (?string $input, ?string $expected): void {
    expect((new UgandanE164Normalizer)->normalize($input))->toBe($expected);
})->with([
    'safaricom 0712 form'          => ['0712345678', '256712345678'],
    '+256 prefixed'                => ['+256712345678', '256712345678'],
    'too short'                    => ['07123', null],
    'invalid prefix (not 7)'       => ['256312345678', null],
]);
```

## Reserved future package additions

The `MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\` namespace
is reserved for normalisers that ship with the package. Custom region
normalisers should live in your application namespace
(`App\Sms\UgandanE164Normalizer`, etc.) so future package upgrades
don't collide with your code.
