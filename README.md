# textsms-laravel-notification-sdk

> Drop-in Laravel notification channel for **TextSMS** (`https://sms.textsms.co.ke`) with a provider-agnostic gateway, event-driven delivery reconciliation, pluggable phone-number normalisation, and an honest test scaffold.

[![Packagist](https://img.shields.io/packagist/v/metasoftdevs/textsms-laravel-notification-sdk.svg)](https://packagist.org/packages/metasoftdevs/textsms-laravel-notification-sdk)
[![Tests](https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/actions/workflows/tests.yml/badge.svg)](https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/metasoftdevs/textsms-laravel-notification-sdk.svg)](LICENSE)

| Requirement | Version |
|---|---|
| PHP | `^8.2` |
| Laravel | `^12.0` |

---

## Quickstart — under 5 minutes

### 1. Install

```bash
composer require metasoftdevs/textsms-laravel-notification-sdk
```

### 2. Publish config + migration

```bash
php artisan vendor:publish --tag=textsms-config
php artisan vendor:publish --tag=textsms-migrations
php artisan migrate
```

### 3. Set credentials in `.env`

```env
TEXTSMS_API_KEY=your-api-key-from-the-portal
TEXTSMS_PARTNER_ID=15682
TEXTSMS_SENDER_ID=YourSender
TEXTSMS_DLR_SECRET=any-long-random-string
```

Generate the DLR secret with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

### 4. Add SMS routing to your `User` model

```php
use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;

public function routeNotificationForSms(?Notification $notification = null): ?string
{
    return app(PhoneNumberNormalizer::class)->normalize($this->phone_number);
}
```

### 5. Write a notification

```php
<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

class OrderShipped extends Notification
{
    public function __construct(public readonly string $trackingNumber) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'sms'];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: __('sms.order_shipped', ['tracking' => $this->trackingNumber]),
            clientReference: 'order-'.$this->trackingNumber,
            metadata: ['tracking_number' => $this->trackingNumber],
        );
    }

    public function toMail(object $notifiable) { /* ... */ }
}
```

### 6. Send

```php
$user->notify(new OrderShipped('TRK-12345'));
```

### 7. Verify

```bash
php artisan textsms:test 0712345678   # one-off send + persisted dispatch row
php artisan textsms:balance           # current provider balance
```

Configure the DLR callback URL on the TextSMS portal as:

```
https://yourapp.com/api/v1/sms/textsms/dlr?token={TEXTSMS_DLR_SECRET}
```

---

## Architecture at a glance

```
Notification::send($user, new OrderShipped(...))
                                │
        via() returns ['mail', 'sms']
                                │
                ┌───────────────┴───────────────┐
                ▼                               ▼
          MailChannel                       SmsChannel ◄──── 'sms' alias
                                                │           registered in
                                                │           TextSmsNotificationServiceProvider::boot()
                                                ▼
                                          SmsSender (interface)
                                                │
                                                ▼
                                       TextSmsGateway (default impl)
                                          │
                                          ├─► Http::post() to TextSMS
                                          ▼
                                  sms_dispatches row
                                  (status=sent, provider_message_id, ...)
                                          │
                                          └─► SmsDispatchAccepted event ──► your audit listeners

                                          ▼
                          TextSMS portal posts DLR back to
                          POST /api/v1/sms/textsms/dlr?token=...
                                          │
                                  VerifyTextSmsCallback middleware
                                          │
                                  TextSmsCallbackController::dlr
                                          │
                                  SmsDeliveryReportProcessor (interface) ──► TextSmsGateway impl
                                          │
                                  status: sent → delivered/failed
                                          │
                                          └─► SmsDeliveryReported event ──► your domain listeners
```

Three load-bearing decisions:

1. **Interface + adapter.** `SmsSender` / `SmsDeliveryReportProcessor` are
   the provider-agnostic contracts. `TextSmsGateway` implements both. Swap
   the binding to swap providers.
2. **`SmsMessage` is a DTO returned from `Notification::toSms()`.** It
   mirrors Laravel's `MailMessage` shape — content only, no recipient.
   Recipient resolution lives on the notifiable via
   `routeNotificationForSms()`.
3. **`sms_dispatches` is the source of truth for delivery state.** Three
   events (`SmsDispatchAccepted`, `SmsDispatchFailed`, `SmsDeliveryReported`)
   surface every transition so consumers can reconcile their own domain
   (receipts, orders, OTP bookkeeping, etc.) without touching the gateway.

See [`docs/architecture.md`](docs/architecture.md) for the full deep-dive.

---

## Configuration reference

The published `config/textsms.php` is reproduced here as the canonical
reference. Every value is environment-overridable.

```php
return [
    'phone_normalizer' => MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer::class,
    'default_sender'   => MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway::class,

    'providers' => [
        'textsms' => [
            'base_url'          => env('TEXTSMS_BASE_URL', 'https://sms.textsms.co.ke'),
            'api_key'           => env('TEXTSMS_API_KEY'),
            'partner_id'        => env('TEXTSMS_PARTNER_ID'),
            'default_sender_id' => env('TEXTSMS_SENDER_ID'),
            'timeout'           => (int) env('TEXTSMS_TIMEOUT', 15),
            'dlr_secret'        => env('TEXTSMS_DLR_SECRET'),
        ],
    ],

    'database' => [
        'connection' => env('TEXTSMS_DB_CONNECTION'),
        'table'      => env('TEXTSMS_DB_TABLE', 'sms_dispatches'),
    ],

    'webhook' => [
        'enabled'    => (bool) env('TEXTSMS_WEBHOOK_ENABLED', true),
        'url_prefix' => env('TEXTSMS_WEBHOOK_PREFIX', 'api/v1/sms'),
        'middleware' => ['throttle:60,1'],
    ],
];
```

---

## Events catalogue

| Event | Fired when | Carries |
|---|---|---|
| `SmsDispatchAccepted` | Provider accepted a dispatch (`status` flipped to `sent`). | `SmsDispatch` |
| `SmsDispatchFailed`   | Provider rejected (any non-200), transport error, or unparseable response. | `SmsDispatch`, `providerCode`, `providerDescription`, `providerErrors`, `parseSource` |
| `SmsDeliveryReported` | DLR webhook posted a delivery report. | `SmsDispatch`, `deliveryStatus` (`delivered`\|`failed`\|`unknown`), `rawStatus`, raw `payload` |

Subscribe listeners in your app's `EventServiceProvider`:

```php
protected $listen = [
    \MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted::class => [
        \App\Listeners\LogSmsAccepted::class,
    ],
    \MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed::class => [
        \App\Listeners\AlertOpsOnSmsFailure::class,
    ],
    \MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported::class => [
        \App\Listeners\SyncReceiptFromSmsDelivery::class,
    ],
];
```

Full event documentation in [`docs/events.md`](docs/events.md).

---

## Testing with the fake gateway

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway;

it('sends a receipt SMS when a payment lands', function () {
    $fake = FakeSmsGateway::install();

    $user = User::factory()->create(['phone_number' => '0712345678']);
    $user->notify(new OrderShipped('TRK-12345'));

    $fake->assertSentCount(1);
    $fake->assertSentTo('254712345678');
});
```

`FakeSmsGateway` implements both `SmsSender` and
`SmsDeliveryReportProcessor`, persists `sms_dispatches` rows, and fires
the real events — so listener-side reconciliation also runs in tests.

---

## Adding a new region (phone normaliser)

The SDK ships only `KenyanE164Normalizer`. Bind a `ChainedNormalizer` in
your `AppServiceProvider` to compose additional regions:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;

$this->app->bind(PhoneNumberNormalizer::class, fn () => new ChainedNormalizer([
    new KenyanE164Normalizer(),
    new \App\Sms\UgandanE164Normalizer(),
    new \App\Sms\TanzanianE164Normalizer(),
]));
```

Full how-to + skeleton class in [`docs/extending-normalizers.md`](docs/extending-normalizers.md).

---

## Writing a custom provider

Implement `SmsSender` (and optionally `SmsDeliveryReportProcessor`),
rebind in `config/textsms.php`, done. The TextSMS adapter is the
reference implementation. See
[`docs/extending-providers.md`](docs/extending-providers.md) for the
full guide.

---

## Artisan commands

| Command | Purpose |
|---|---|
| `php artisan textsms:balance` | Print the provider's current account balance. |
| `php artisan textsms:test {phone} [--body=...] [--sender=...]` | Send one test SMS through the configured gateway. |
| `php artisan textsms:dispatch:show {ulid}` | Pretty-print a single `sms_dispatches` row. |

---

## Roadmap

Tracked in [`docs/roadmap.md`](docs/roadmap.md). Out-of-scope items in v1.0
that have a documented extension recipe:

- Multi-sender-ID pools.
- Multi-provider failover.
- HMAC-signed DLR webhooks.
- Provider-side scheduled sends (`timeToSend`).
- Queued-sender decorator.

---

## Licence

MIT. See [`LICENSE`](LICENSE).

## Support

- Issues: <https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/issues>
- Email: **support@metasoftdevs.com** (or **info@metasoftdevs.com**)
- Security disclosures: see [`CONTRIBUTING.md`](CONTRIBUTING.md#reporting-a-security-issue)

## Acknowledgements

Extracted and hardened over seven iterative rounds inside the Quest
Pinnacle Investments Management System (QPIMS) before being released
under the MetaSoftDevs umbrella. See [`CONTRIBUTING.md`](CONTRIBUTING.md)
for the design philosophy that informs every change.
