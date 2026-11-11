> Historical extraction plan. The owner-approved November 9 resolutions
> supersede its delivery transitions/events, broad retry and default throttle
> proposals. Current behavior: passive callback payloads, one cURL 6/7 retry,
> authenticated configurable 600/minute limiter. See [webhooks](webhooks.md),
> [events](events.md) and [operations](operations.md). Examples below are
> historical design context, not current implementation instructions.

# Implementation Plan: Publish `metasoftdevs/textsms-laravel-notification-sdk`

## Context

The SMS gateway built inside QPIMS (`app/Services/Sms/`, `app/Notifications/Channels/SmsChannel.php`,
`app/Models/SmsDispatch.php`, `app/Http/Middleware/Api/VerifyTextSmsCallback.php`,
`app/Http/Controllers/Api/V1/Sms/TextSmsCallbackController.php`) has stabilised after seven
rounds of hardening (docs `01-04` through `07` in `.docs/sms-gateway/`) and is now in active
production use against the real TextSMS endpoint. The audit in
`.docs/sms-gateway/dev-module-reflection/01-module-as-built.md` (the "Reflection") concluded
that the architecture is shippable but is blocked from extraction by four named application
couplings (§3.1–3.4 of the Reflection).

This plan ships the extracted SDK as a stand-alone Composer package under the
**MetaSoftDevs** GitHub organisation (reference: `metasoftdevs/laravel-breeze-2fa`,
namespace `MetaSoftDevs\LaravelBreeze2FA`), targeting **Laravel 12+** and **PHP 8.2+**.

**Package identity (locked by user choice during planning):**

| Field | Value |
|---|---|
| Composer name | `metasoftdevs/textsms-laravel-notification-sdk` |
| PSR-4 namespace | `MetaSoftDevs\TextSmsLaravelNotificationSdk` |
| Service provider | `MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider` |
| License | MIT |
| Minimum PHP | 8.2 |
| Minimum Laravel | 12.0 |
| Default gateway | TextSMS (`https://sms.textsms.co.ke`) |
| Default phone normaliser | `KenyanE164Normalizer` (Safaricom/Airtel/Telkom) |

Every refactor below traces back to a numbered finding in the Reflection.

---

## 1. Package goals and non-goals

**Goals:**
- Drop-in Laravel notification channel `'sms'` so any notification can opt in via `via()`.
- Provider-agnostic interface with **TextSMS as the default** concrete implementation (preserves the QPIMS shape).
- Event-driven extension points so consumers wire their own audit / domain reconciliation without modifying SDK code.
- Pluggable phone normalisation — Kenyan-first, extensible to any region without forking.
- Publishable config, migration, routes, and stubs.
- Honest test scaffolding so consumers can `use MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway` and write assertions.

**Non-goals (out of scope for v1.0):**
- Multi-sender-ID rotation. Preserve the single-sender model from QPIMS — current implementation reads a single `default_sender_id` from config; per-message override via `SmsMessage::senderId` is supported. Multi-sender pools are tracked for v1.1.
- Multi-provider failover (`FailoverSmsSender`). Stubbed as a documented extension point but not shipped.
- Provider-side scheduled sends (`timeToSend`). Listed as a future enhancement in the README.

---

## 2. Directory structure (PSR-4 root: `src/`)

Mirrors `metasoftdevs/laravel-breeze-2fa` exactly so MetaSoftDevs packages have one shape.

```
metasoftdevs/textsms-laravel-notification-sdk/
├── .github/
│   └── workflows/
│       ├── tests.yml                    # Pest, matrix: PHP 8.2/8.3, Laravel 12.*
│       └── static-analysis.yml          # phpstan + pint --test
├── config/
│   └── textsms.php                      # See §6
├── database/
│   └── migrations/
│       └── 2026_06_08_000000_create_sms_dispatches_table.php.stub
├── docs/
│   ├── README.md                        # Mirrors top-level README, longer form
│   ├── extending-providers.md           # How to implement another SmsGateway
│   ├── extending-normalizers.md         # How to ship a non-Kenyan normaliser
│   ├── events.md                        # Event catalogue + listener wiring
│   ├── webhooks.md                      # Configuring the DLR callback URL on provider portal
│   └── upgrade-guide.md                 # Empty for v1.0; populated as breaking changes land
├── examples/
│   ├── ReceiptNotification.php          # End-to-end sample notification with toMail + toSms
│   └── OtpNotification.php              # SMS-only sample for 2FA-style flows
├── resources/
│   └── views/
│       └── (none in v1.0 — no Blade templates published)
├── routes/
│   └── webhooks.php                     # Opt-in DLR route registration
├── src/
│   ├── Contracts/
│   │   ├── SmsSender.php                # send/sendBulk/balance — narrower than the QPIMS SmsGateway
│   │   ├── SmsDeliveryReportProcessor.php # recordDeliveryReport split out (Reflection §4.2)
│   │   └── PhoneNumberNormalizer.php    # normalize/isValid (Reflection §3.3)
│   ├── Messages/
│   │   └── SmsMessage.php               # Immutable DTO, unchanged from QPIMS shape
│   ├── Notifications/
│   │   └── Channels/
│   │       └── SmsChannel.php           # Returns ?SmsDispatch; uses injected SmsSender
│   ├── Gateways/
│   │   └── TextSmsGateway.php           # The QPIMS implementation, stripped of receipt/ActivityLogger coupling
│   ├── PhoneNumbers/
│   │   ├── KenyanE164Normalizer.php     # Default, the QPIMS rules verbatim
│   │   └── ChainedNormalizer.php        # Tries each in order — for consumers needing multi-region
│   ├── Models/
│   │   └── SmsDispatch.php              # Connection + table name from config (Reflection §6 risk register)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── TextSmsCallbackController.php
│   │   └── Middleware/
│   │       └── VerifyTextSmsCallback.php
│   ├── Events/
│   │   ├── SmsDispatchAccepted.php      # Fired when status flips pending → sent (§3.1, §3.2)
│   │   ├── SmsDispatchFailed.php        # Fired when status flips pending → failed
│   │   └── SmsDeliveryReported.php      # Fired from recordDeliveryReport
│   ├── Exceptions/
│   │   ├── SmsDispatchException.php     # Verbatim from QPIMS
│   │   └── SmsProviderException.php     # Verbatim from QPIMS, with providerErrors
│   ├── Console/
│   │   └── Commands/
│   │       ├── BalanceCommand.php       # php artisan textsms:balance
│   │       ├── TestSendCommand.php      # php artisan textsms:test {phone}
│   │       └── ShowDispatchCommand.php  # php artisan textsms:dispatch:show {ulid}
│   ├── Testing/
│   │   └── FakeSmsGateway.php           # Replaces inline FakeSmsGatewayForChannelTest from QPIMS
│   └── TextSmsNotificationServiceProvider.php
├── stubs/
│   └── (none in v1.0 — migration is the only publishable artefact)
├── tests/
│   ├── Pest.php
│   ├── TestCase.php                     # extends Orchestra\Testbench\TestCase
│   ├── Feature/
│   │   ├── TextSmsGatewayTest.php       # The 15 QPIMS cases, ported
│   │   ├── SmsChannelTest.php           # The 4 QPIMS cases, ported, using FakeSmsGateway from src/Testing
│   │   └── WebhookTest.php              # The 8 QPIMS cases, ported
│   └── Unit/
│       └── KenyanE164NormalizerTest.php # The 23 QPIMS cases, ported
├── .gitattributes                       # Excludes tests/, docs/, .github/ from Composer dist tarball
├── .gitignore
├── .php-cs-fixer.dist.php               # Matches MetaSoftDevs house style
├── CHANGELOG.md                         # Keep-a-changelog format
├── CONTRIBUTING.md
├── LICENSE                              # MIT
├── README.md                            # Quickstart + installation + 5-minute usage
├── composer.json
├── phpstan.neon.dist                    # level 8
└── phpunit.xml.dist                     # Wraps Pest
```

---

## 3. Refactors against the Reflection's blockers

### 3.1 — Decouple Receipt-domain logic from the gateway (Reflection §3.1)

**Removed from `TextSmsGateway` entirely** (these methods do not exist in the SDK):
- `syncReceiptFromDeliveryReport()`
- `resolveReceiptForDispatch()`
- `isSmsResendReason()`
- The `use App\Models\Receipt;` import

**Replaced with three events** fired at the same code points:

```php
// src/Events/SmsDispatchAccepted.php
final class SmsDispatchAccepted
{
    public function __construct(public readonly SmsDispatch $dispatch) {}
}

// src/Events/SmsDispatchFailed.php
final class SmsDispatchFailed
{
    public function __construct(
        public readonly SmsDispatch $dispatch,
        public readonly ?string $providerCode,
        public readonly ?string $providerDescription,
        public readonly ?array $providerErrors,
        public readonly ?string $parseSource,
    ) {}
}

// src/Events/SmsDeliveryReported.php
final class SmsDeliveryReported
{
    public function __construct(
        public readonly SmsDispatch $dispatch,
        public readonly string $deliveryStatus, // 'delivered' | 'failed' | 'unknown'
        public readonly string $rawStatus,
        public readonly array $payload,
    ) {}
}
```

QPIMS consumes the SDK by registering one listener:

```php
// QPIMS: app/Providers/EventServiceProvider.php
protected $listen = [
    SmsDeliveryReported::class => [SyncReceiptFromSmsDelivery::class],
    SmsDispatchAccepted::class => [LogSmsAccepted::class],
    SmsDispatchFailed::class   => [LogSmsFailed::class],
];
```

…and the receipt sync logic that currently lives in `TextSmsGateway::syncReceiptFromDeliveryReport()`
moves into QPIMS's own `app/Listeners/SyncReceiptFromSmsDelivery.php`. The string-prefix
`isSmsResendReason()` deferral from `05-resend-attempted-channel-fix.md` is **resolved by removal** — the listener decides its own resend-reason taxonomy.

### 3.2 — Replace `ActivityLogger` with PSR-3 + events (Reflection §3.2)

The SDK depends on `Psr\Log\LoggerInterface`, resolved from the container, for **debug/warning lines only** (e.g. "DLR for unknown messageid"). All audit-grade events go through the §3.1 events. QPIMS's existing `ActivityLogger::log()` is wired via the listeners above. Other consumers wire whatever they want — Sentry breadcrumbs, ELK, the standard Laravel log, etc.

### 3.3 — Pluggable phone normalisation (Reflection §3.3)

```php
// src/Contracts/PhoneNumberNormalizer.php
interface PhoneNumberNormalizer
{
    public function normalize(?string $raw): ?string;   // E.164 without leading + (or null if invalid)
    public function isValid(?string $candidate): bool;
}

// src/PhoneNumbers/KenyanE164Normalizer.php
final class KenyanE164Normalizer implements PhoneNumberNormalizer
{
    // Verbatim Kenyan rules from app/Services/Sms/Support/PhoneNumber.php — Safaricom 7xx + Airtel/Telkom 1xx
}

// src/PhoneNumbers/ChainedNormalizer.php
final class ChainedNormalizer implements PhoneNumberNormalizer
{
    /** @param list<PhoneNumberNormalizer> $normalizers Tried in order; first non-null wins. */
    public function __construct(private readonly array $normalizers) {}
}
```

**Default binding (set in `TextSmsNotificationServiceProvider::register()`):**
```php
$this->app->bind(PhoneNumberNormalizer::class, KenyanE164Normalizer::class);
```

**Consumer override** (e.g. a multi-region SaaS):
```php
// AppServiceProvider::register()
$this->app->bind(PhoneNumberNormalizer::class, fn() => new ChainedNormalizer([
    new KenyanE164Normalizer,
    new UgandanE164Normalizer,
    new TanzanianE164Normalizer,
]));
```

**Why a chain rather than a registry**: a chain mirrors the user's stated requirement ("Kenyan first, flexibility for additional regions"), keeps the SDK shipping with zero ambiguity about which normaliser is default, and pushes ordering decisions into consumer code where they belong. Per-region normalisers (Ugandan, Tanzanian, etc.) are **not** shipped in v1.0 — they're documented in `docs/extending-normalizers.md` with a how-to.

### 3.4 — Namespace and path migration (Reflection §3.4)

Every class moves verbatim from `App\…` to `MetaSoftDevs\TextSmsLaravelNotificationSdk\…`:

| QPIMS path | SDK path |
|---|---|
| `app/Services/Sms/Contracts/SmsGateway.php` | `src/Contracts/SmsSender.php` + `src/Contracts/SmsDeliveryReportProcessor.php` (split per §4.2) |
| `app/Services/Sms/Messages/SmsMessage.php` | `src/Messages/SmsMessage.php` (verbatim) |
| `app/Services/Sms/Gateways/TextSmsGateway.php` | `src/Gateways/TextSmsGateway.php` (minus receipt logic) |
| `app/Services/Sms/Support/PhoneNumber.php` | `src/PhoneNumbers/KenyanE164Normalizer.php` |
| `app/Notifications/Channels/SmsChannel.php` | `src/Notifications/Channels/SmsChannel.php` (verbatim shape) |
| `app/Services/Sms/Exceptions/*` | `src/Exceptions/*` (verbatim) |
| `app/Models/SmsDispatch.php` | `src/Models/SmsDispatch.php` (table/connection from config) |
| `app/Http/Middleware/Api/VerifyTextSmsCallback.php` | `src/Http/Middleware/VerifyTextSmsCallback.php` |
| `app/Http/Controllers/Api/V1/Sms/TextSmsCallbackController.php` | `src/Http/Controllers/TextSmsCallbackController.php` |

---

## 4. Polish items addressed (Reflection §4)

| Reflection ref | What ships in v1.0 |
|---|---|
| §4.1 No second concrete provider | Ship a `LogOnlySmsGateway` under `src/Gateways/` (for `local` env / CI). Africa's Talking is documented as a future provider in `docs/extending-providers.md`. |
| §4.2 Interface conflates outbound and inbound | **Split into two interfaces.** `SmsSender` (`send`, `sendBulk`, `balance`) and `SmsDeliveryReportProcessor` (`record`). `TextSmsGateway` implements both. |
| §4.3 No queue strategy | The notification keeps `ShouldQueue` as before. A `QueuedSmsSender` decorator is documented in `docs/queueing.md` (1-page how-to) but **not** shipped — caller decides. |
| §4.4 No retry policy abstraction | Ship a tiny `RetryPolicy` interface with a `TextSmsRetryPolicy` default that **does not** retry on `1003`/`1004`/`1006` and **does** retry on transport errors. |
| §4.5 No multi-provider failover | Out of scope v1.0. Documented as an extension pattern. |
| §4.6 No artisan tooling | Ship three commands: `textsms:balance`, `textsms:test {phone}`, `textsms:dispatch:show {ulid}`. `textsms:dispatch:retry-failed` is documented but **not** shipped (retry semantics are domain-specific). |
| §4.7 No published test helpers | Ship `MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway` with `assertSentTo()`, `assertNothingSent()`, `assertSentCount()`. |
| §4.8 Static `PhoneNumber` hard to mock | Resolved by §3.3 (interface + DI). |
| §4.9 No HMAC option for DLR webhooks | Ship token-only `VerifyTextSmsCallback` (TextSMS doesn't sign). HMAC documented as future. |
| §4.10 `generateClientId()` collision risk | Replace with `Illuminate\Support\Str::ulid()->toBase32()`. Documented in CHANGELOG. |
| §4.11 Body not localised in docs | README sample uses `__('sms.receipt_body', [...])`. |

---

## 5. Single sender-ID handling (per user direction)

Configuration carries a single `default_sender_id` (preserved from `services.textsms.sender_id` in QPIMS, currently `QPIVL`). Per-message override remains available via `new SmsMessage(body: '...', senderId: 'CUSTOM')`. Multi-sender pools are explicitly **out of scope** and tracked under `docs/roadmap.md` as a v1.1 target. The README states this clearly so consumers don't ask.

---

## 6. Configuration (`config/textsms.php`)

```php
return [
    /*
     | Default phone-number normaliser binding. The SDK ships
     | KenyanE164Normalizer; consumers can rebind to ChainedNormalizer
     | in their own AppServiceProvider. See docs/extending-normalizers.md.
     */
    'phone_normalizer' => MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer::class,

    /*
     | The default outbound SMS sender (channel binding for Notification::send()).
     | Defaults to the TextSMS gateway. Swap to LogOnlySmsGateway for local dev,
     | or to your own implementation for multi-provider scenarios.
     */
    'default_sender' => MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway::class,

    'providers' => [
        'textsms' => [
            'base_url'           => env('TEXTSMS_BASE_URL', 'https://sms.textsms.co.ke'),
            'api_key'            => env('TEXTSMS_API_KEY'),
            'partner_id'         => env('TEXTSMS_PARTNER_ID'),
            'default_sender_id'  => env('TEXTSMS_SENDER_ID', 'QPIVL'),
            'timeout'            => env('TEXTSMS_TIMEOUT', 15),
            'dlr_secret'         => env('TEXTSMS_DLR_SECRET'),
        ],
    ],

    'database' => [
        'connection' => env('TEXTSMS_DB_CONNECTION', null),
        'table'      => env('TEXTSMS_DB_TABLE', 'sms_dispatches'),
    ],

    'webhook' => [
        'enabled'    => env('TEXTSMS_WEBHOOK_ENABLED', true),
        'url_prefix' => env('TEXTSMS_WEBHOOK_PREFIX', 'api/v1/sms'),
        'middleware' => ['throttle:60,1'],
        // The verify.textsms.callback alias is auto-registered by the service provider.
    ],
];
```

Publishable via `php artisan vendor:publish --tag=textsms-config`.

---

## 7. Service Provider responsibilities

`TextSmsNotificationServiceProvider`:

**`register()`**
- Merges `config/textsms.php` (defaults).
- Binds `PhoneNumberNormalizer::class` → `config('textsms.phone_normalizer')`.
- Binds `SmsSender::class` and `SmsDeliveryReportProcessor::class` → `config('textsms.default_sender')`.
- Constructs the concrete `TextSmsGateway` with config-injected base URL, API key, partner ID, default sender ID, timeout, normalizer, and PSR-3 logger.

**`boot()`**
- Registers the `'sms'` channel alias via `ChannelManager::extend()`.
- Registers the middleware alias `verify.textsms.callback`.
- When `config('textsms.webhook.enabled')` is true, loads `routes/webhooks.php`.
- Publishes config (`textsms-config`), migrations (`textsms-migrations`), and routes (`textsms-routes`).
- Registers the three artisan commands when running in console.

---

## 8. README quickstart contract

The top-level `README.md` must let a consumer go from `composer require` to a queued SMS in **under 5 minutes**:

1. `composer require metasoftdevs/textsms-laravel-notification-sdk`
2. `php artisan vendor:publish --tag=textsms-config`
3. `php artisan vendor:publish --tag=textsms-migrations && php artisan migrate`
4. Set five env vars (`TEXTSMS_API_KEY`, `TEXTSMS_PARTNER_ID`, `TEXTSMS_SENDER_ID`, `TEXTSMS_DLR_SECRET`, `TEXTSMS_WEBHOOK_PREFIX`).
5. Add `routeNotificationForSms()` to the `User` model (one-liner using the resolved `PhoneNumberNormalizer`).
6. Write a notification with `via()` returning `['mail', 'sms']` and a `toSms($notifiable): SmsMessage` method.
7. `php artisan textsms:test 0712345678` confirms outbound. Configure the DLR URL on the TextSMS portal.

Sections after the quickstart:
- Architecture diagram (taken from `.docs/sms-gateway/README.md` of QPIMS)
- Configuration reference
- Events catalogue
- "Writing your own provider" (`docs/extending-providers.md` linked from here)
- "Adding a region" (`docs/extending-normalizers.md` linked from here)
- Testing with `FakeSmsGateway`
- Roadmap (multi-sender, failover, HMAC webhooks)

Every section has a code block. Every code block uses `__('sms.receipt_body', [...])` where applicable.

---

## 9. Code-quality requirements (per user direction)

Every shipped class must have:

1. **A class-level docblock** explaining purpose, lifecycle, and any provider-specific quirk (e.g. the `respose-code` misspelling).
2. **Method docblocks** with `@param`, `@return`, `@throws` for every public/protected method.
3. **Strict types declaration** (`declare(strict_types=1);`) at file top.
4. **Constructor property promotion** with `public readonly` for immutable DTOs and `private readonly` for injected dependencies.
5. **Explicit return types** including `void`, `?T`, and array-shape generics where Pest tests assert keys (e.g. `array{global: ?array{...}, perClient: array<string, ...>}`).
6. **No comment without a Why** — comments explain provider quirks and load-bearing decisions, never restating what the code already says.

Enforced by `phpstan.neon.dist` at level 8 and a `.php-cs-fixer.dist.php` mirroring MetaSoftDevs house style.

---

## 10. Test strategy

Reuse the QPIMS regression suite verbatim where possible — every test ported retains the same `it('...')` description, only swapping namespaces and the `FakeSmsGateway` source location.

| Test file | Source in QPIMS | Coverage carried over |
|---|---|---|
| `tests/Feature/TextSmsGatewayTest.php` | `tests/Feature/Sms/TextSmsGatewayTest.php` | 15 cases: happy path, 1004 low credits, validation 422 (Shape B), unparseable 503 (Shape C single), bulk top-level, bulk unparseable, balance, etc. |
| `tests/Feature/SmsChannelTest.php` | `tests/Feature/Sms/SmsChannelTest.php` | 4 cases: dispatches via gateway, skips silently on no phone, skips on unnormalisable phone, does not rethrow gateway exceptions |
| `tests/Feature/WebhookTest.php` | `tests/Feature/Api/SmsCallbackTest.php` | 8 cases: delivered DLR, failed DLR, undelivered classified failed, missing token 401, wrong token 401, header token accepted, unknown messageid 200, missing messageid 422 |
| `tests/Unit/KenyanE164NormalizerTest.php` | `tests/Unit/Sms/PhoneNumberTest.php` | 23 dataset cases |

**Total target**: 50+ tests (was 91 in QPIMS, of which receipt-coupled tests stay in the consumer app).

The test suite runs against `Orchestra\Testbench` so we boot a minimal Laravel app inside the package's own `phpunit`. CI matrix: PHP 8.2 + 8.3, Laravel 12.* stable + dev-master.

---

## 11. Migration shape (`database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub`)

Verbatim copy of `database/migrations/2026_05_08_032936_create_sms_dispatches_table.php` from QPIMS, with two parameterisations:

1. Table name read from `config('textsms.database.table', 'sms_dispatches')`.
2. Connection read from `config('textsms.database.connection')` (nullable → default).

Publishable via `php artisan vendor:publish --tag=textsms-migrations`, which copies the `.stub` into the consumer's `database/migrations/` with a fresh timestamp.

---

## 12. Routes (`routes/webhooks.php`)

```php
use Illuminate\Support\Facades\Route;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers\TextSmsCallbackController;

Route::prefix(config('textsms.webhook.url_prefix'))
    ->name('metasoftdevs.textsms.')
    ->middleware(config('textsms.webhook.middleware', []))
    ->group(function (): void {
        Route::post('textsms/dlr', [TextSmsCallbackController::class, 'dlr'])
            ->middleware('verify.textsms.callback')
            ->name('dlr');
    });
```

Auto-loaded by `TextSmsNotificationServiceProvider::boot()` when
`config('textsms.webhook.enabled')` is true. Publishable for consumers who want to
customise URL shape or middleware.

---

## 13. Reference benchmarks (every file in QPIMS that the SDK extraction traces back to)

This list is the **exclusive ground-truth** for the porting work. Any deviation from these files must be justified in the per-class PR description.

```
QPIMS file → SDK src/ destination
─────────────────────────────────────────────────────────────────────────
app/Services/Sms/Contracts/SmsGateway.php
    → split into src/Contracts/SmsSender.php
    → and    src/Contracts/SmsDeliveryReportProcessor.php

app/Services/Sms/Messages/SmsMessage.php
    → src/Messages/SmsMessage.php           (verbatim)

app/Services/Sms/Gateways/TextSmsGateway.php
    → src/Gateways/TextSmsGateway.php       (strip syncReceiptFromDeliveryReport,
                                             resolveReceiptForDispatch, isSmsResendReason,
                                             App\Models\Receipt, App\Services\ActivityLogger.
                                             Insert event::dispatch() at the equivalent
                                             code points. See §3.1.)

app/Services/Sms/Support/PhoneNumber.php
    → src/PhoneNumbers/KenyanE164Normalizer.php   (implements PhoneNumberNormalizer)

app/Notifications/Channels/SmsChannel.php
    → src/Notifications/Channels/SmsChannel.php   (verbatim shape; depends on SmsSender,
                                                   not the old SmsGateway interface)

app/Services/Sms/Exceptions/SmsDispatchException.php
    → src/Exceptions/SmsDispatchException.php     (verbatim)

app/Services/Sms/Exceptions/SmsProviderException.php
    → src/Exceptions/SmsProviderException.php     (verbatim, with providerErrors)

app/Models/SmsDispatch.php
    → src/Models/SmsDispatch.php                  (table/connection from config)

app/Http/Middleware/Api/VerifyTextSmsCallback.php
    → src/Http/Middleware/VerifyTextSmsCallback.php

app/Http/Controllers/Api/V1/Sms/TextSmsCallbackController.php
    → src/Http/Controllers/TextSmsCallbackController.php

database/migrations/2026_05_08_032936_create_sms_dispatches_table.php
    → database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub

tests/Feature/Sms/TextSmsGatewayTest.php
    → tests/Feature/TextSmsGatewayTest.php

tests/Feature/Sms/SmsChannelTest.php
    → tests/Feature/SmsChannelTest.php             (FakeSmsGateway from src/Testing/)

tests/Feature/Api/SmsCallbackTest.php
    → tests/Feature/WebhookTest.php

tests/Unit/Sms/PhoneNumberTest.php
    → tests/Unit/KenyanE164NormalizerTest.php

.docs/sms-gateway/README.md                       → docs/architecture.md (architecture section only)
.docs/sms-gateway/TextSmsGateway.md               → docs/extending-providers.md (matrix reused)
.docs/sms-gateway/SmsExceptions.md                → docs/events.md (cross-reference)
.docs/sms-gateway/07-validation-error-parsing.md  → docs/upgrade-guide.md (post-1.0 example entry)
.docs/sms-gateway/dev-module-reflection/
    01-module-as-built.md                         → CONTRIBUTING.md (philosophy section)
```

---

## 14. Execution order

1. **Scaffold the repo on GitHub.** Empty `metasoftdevs/textsms-laravel-notification-sdk`, MIT licence, README placeholder.
2. **`composer init`** with the package metadata from §1.
3. **Port classes** in this strict order (each pass = one PR, easy to review):
   1. Exceptions → Messages → Models (no dependencies between them)
   2. Contracts (`SmsSender`, `SmsDeliveryReportProcessor`, `PhoneNumberNormalizer`)
   3. `KenyanE164Normalizer` + its unit test
   4. `TextSmsGateway` (stripped) + the gateway feature test
   5. `SmsChannel` + the channel test (with `FakeSmsGateway` in `src/Testing/`)
   6. Middleware + controller + webhook test
   7. Events + sample listeners in `examples/`
   8. Artisan commands
   9. `TextSmsNotificationServiceProvider` (last, because it wires everything)
4. **Documentation pass.** README quickstart, then `docs/*.md` files in order.
5. **CI.** GitHub Actions matrix for PHP 8.2/8.3 × Laravel 12.* stable.
6. **Tag `v1.0.0-rc1`** and publish to a draft Packagist mirror or your own private registry for smoke install in a fresh Laravel 12 app.
7. **Smoke install in a throwaway Laravel 12 project.** Follow the README quickstart end-to-end. Send one real SMS to `07XXXXXXXX` (the test recipient already approved for QPIMS-side testing) via `textsms:test`. Configure DLR. Confirm a real delivery report flips the `sms_dispatches` row to `delivered`.
8. **Migrate QPIMS** to consume the SDK. Drop `app/Services/Sms/`, `app/Notifications/Channels/SmsChannel.php`, the SMS controller, the SMS middleware, the SMS migration, and the SMS test files. Replace with a Listener in `app/Listeners/SyncReceiptFromSmsDelivery.php` that handles the SDK's `SmsDeliveryReported` event. Re-run the QPIMS full suite — expect identical 91/272 pass count because every Receipt-side test stays in QPIMS.
9. **Tag `v1.0.0`.** Push to Packagist.

---

## 15. Verification

End-to-end acceptance test for the SDK before release:

1. Inside the SDK repo: `composer test` → 50+ Pest assertions green.
2. Inside the SDK repo: `composer phpstan` → level 8 clean.
3. Inside the SDK repo: `composer pint --test` → clean.
4. Inside a throwaway `laravel new sms-smoke` project: `composer require metasoftdevs/textsms-laravel-notification-sdk:^1.0.0-rc1` → installs without conflicts.
5. README quickstart steps 1–7 → real SMS delivered to `07XXXXXXXX` AND `sms_dispatches` row populated with `provider_message_id`.
6. Configure DLR URL on TextSMS portal → wait < 5 min → `sms_dispatches.status` flips from `sent` to `delivered`.
7. Inside the throwaway project, write a 20-line notification using `via(['sms'])` and `toSms()` returning `new SmsMessage(__('demo.body', ['name' => $notifiable->name]))`. Send it. Confirm SMS arrives.
8. Inside the QPIMS branch that migrates to the SDK: `php artisan test tests/Feature/Sms tests/Feature/Api/SmsCallbackTest.php tests/Unit/Sms tests/Feature/ReceiptServiceTest.php tests/Feature/ReceiptDetailLivewireTest.php tests/Feature/Admin/ReceiptControllerTest.php` → **91 passed, 272 assertions** (identical to current QPIMS baseline).
9. The `SyncReceiptFromSmsDelivery` listener exercised end-to-end: real DLR on a real receipt SMS → `receipts.sms_delivered_at` populated. No app-side regressions.

---

## 16. Out of scope (explicitly deferred)

| Item | Tracked at |
|---|---|
| Multi-sender-ID pools | `docs/roadmap.md` (v1.1 target) |
| Multi-provider failover (`FailoverSmsSender`) | `docs/extending-providers.md` (documented extension pattern) |
| HMAC-signed webhooks | `docs/webhooks.md` (v1.x target, when a provider that uses HMAC is added) |
| Provider-side `timeToSend` scheduling | `docs/roadmap.md` |
| Queued sender (`QueuedSmsSender` decorator) | `docs/queueing.md` (recipe, not shipped class) |
| Bulk batcher above 20 | `docs/bulk-batching.md` (recipe) |
| Region normalisers other than Kenyan | `docs/extending-normalizers.md` (how-to with skeleton) |
| `textsms:dispatch:retry-failed` artisan | `docs/operations.md` (recipe — semantics are domain-specific) |

Each deferral has a documented recipe so consumers aren't blocked; the SDK simply doesn't ship the class.

---

## 17. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Breaking changes between Laravel 12.x and 13.x | Pin `"laravel/framework": "^12.0 \|\| ^13.0"` after a smoke-test on 13.0; document with a CHANGELOG `BC BREAK` heading per release. |
| Composer name typo'd in publication | The Packagist publish step requires manual confirmation; the README quickstart is the integration test against the published name. |
| Migration timestamp collisions on consumer side | Use `.stub` extension so `vendor:publish` rewrites the timestamp on copy. |
| Webhook URL exposed without secret in dev | The `VerifyTextSmsCallback` middleware returns 500 (not 401) when the secret is unconfigured, forcing explicit ops setup. |
| Consumers binding `SmsSender` to a custom class that doesn't return an `SmsDispatch` | `SmsSender::send(): SmsDispatch` is a typed return; consumer's bad binding fails at type-check, not at runtime. |
| The user later wants multi-sender-ID before v1.1 | The DTO already supports per-message override via `senderId:`; consumer can hand-roll multi-sender selection in their own service. |

---

## Critical files (this plan's authoritative file list for the porting work)

All paths relative to the new SDK repo root.

```
composer.json
src/TextSmsNotificationServiceProvider.php
src/Contracts/SmsSender.php
src/Contracts/SmsDeliveryReportProcessor.php
src/Contracts/PhoneNumberNormalizer.php
src/Messages/SmsMessage.php
src/Gateways/TextSmsGateway.php
src/Gateways/LogOnlySmsGateway.php
src/PhoneNumbers/KenyanE164Normalizer.php
src/PhoneNumbers/ChainedNormalizer.php
src/Models/SmsDispatch.php
src/Notifications/Channels/SmsChannel.php
src/Http/Controllers/TextSmsCallbackController.php
src/Http/Middleware/VerifyTextSmsCallback.php
src/Events/SmsDispatchAccepted.php
src/Events/SmsDispatchFailed.php
src/Events/SmsDeliveryReported.php
src/Exceptions/SmsDispatchException.php
src/Exceptions/SmsProviderException.php
src/Console/Commands/BalanceCommand.php
src/Console/Commands/TestSendCommand.php
src/Console/Commands/ShowDispatchCommand.php
src/Testing/FakeSmsGateway.php
config/textsms.php
database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub
routes/webhooks.php
tests/TestCase.php
tests/Pest.php
tests/Feature/TextSmsGatewayTest.php
tests/Feature/SmsChannelTest.php
tests/Feature/WebhookTest.php
tests/Unit/KenyanE164NormalizerTest.php
examples/ReceiptNotification.php
examples/OtpNotification.php
README.md
docs/architecture.md
docs/events.md
docs/extending-providers.md
docs/extending-normalizers.md
docs/webhooks.md
docs/queueing.md
docs/bulk-batching.md
docs/operations.md
docs/roadmap.md
docs/upgrade-guide.md
CONTRIBUTING.md
CHANGELOG.md
LICENSE
.github/workflows/tests.yml
.github/workflows/static-analysis.yml
.gitattributes
.gitignore
.php-cs-fixer.dist.php
phpstan.neon.dist
phpunit.xml.dist
```
