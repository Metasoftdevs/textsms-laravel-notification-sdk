# Roadmap

Tracked work-in-progress and future enhancements. Items are grouped by
target release; nothing here is committed timeline-wise.

## v1.0 — implemented locally, release checks pending

- Provider-agnostic `SmsSender` + `SmsDeliveryReportProcessor` contracts.
- `TextSmsGateway` default concrete implementation.
- `KenyanE164Normalizer` + `ChainedNormalizer`.
- Two outbound events: `SmsDispatchAccepted`, `SmsDispatchFailed`.
- Informational delivery reports only; legacy `SmsDeliveryReported` remains
  loadable but is not emitted. No delivery precedence or application actions.
- One bounded send retry for proven cURL 6/7 connection failures.
- Authenticated configurable callback limiter, default 600/minute.
- `SmsChannel` Laravel notification channel registered as `'sms'`.
- DLR webhook controller + `VerifyTextSmsCallback` middleware.
- `sms_dispatches` table migration (native publish-once PHP migration).
- Three artisan commands: `textsms:balance`, `textsms:test`, `textsms:dispatch:show`.
- `Testing\FakeSmsGateway` with `assertSentTo()`, `assertNothingSent()`, `assertSentCount()`.
- `LogOnlySmsGateway` for local development.
- Pest + Orchestra Testbench regression suite.
- PHPStan level 8 clean.
- Prepared twelve-row CI for Laravel 12 on PHP 8.2/8.3/8.4 and Laravel 13
  on PHP 8.3/8.4/8.5, each at lowest/stable dependencies. Two local Laravel13
  graphs pass; the complete matrix on the release commit remains pending.

## v1.1 — multi-sender ID pools

Configure multiple approved sender IDs and have the gateway pick one
per dispatch (round-robin, weighted, or domain-specific). Today the SDK
ships a single `default_sender_id` plus per-message `SmsMessage::senderId`
override; per-message override remains the supported v1.0 path.

```php
// Future config shape (illustrative — not yet implemented):
'providers' => [
    'textsms' => [
        // ...
        'sender_id_pool' => [
            ['id' => 'QPIVL',   'weight' => 1, 'tag' => 'default'],
            ['id' => 'QPIVL-A', 'weight' => 2, 'tag' => 'alerts'],
        ],
        'sender_id_strategy' => 'weighted', // | 'round-robin' | 'tag-based'
    ],
],
```

## v1.x — multi-provider failover

An application-owned fallback policy could select another provider only
when the previous attempt is proven safe to repeat. `SmsProviderException`
alone is insufficient: accepted-but-unrecorded and unknown outcomes must
not fall through automatically. See [provider extensions](extending-providers.md).
No automatic failover class or general retry-policy interface is shipped.

## v1.x — HMAC-signed webhooks

This SDK does not verify callback signatures. Provider/account signing
capabilities need confirmation before designing an HMAC extension. The roadmap includes shipping a
sibling `HmacSignedMiddleware` alongside the current
`VerifyTextSmsCallback` when at least one HMAC-signing provider lands.

## v1.x — provider-side scheduled sends

TextSMS accepts a `timeToSend` parameter to queue a future send on the
provider side. The DTO currently has no scheduled-at field; adding it
would touch `SmsMessage`, `TextSmsGateway::send()`, and the bulk path.
Targeted for when at least one consumer requests it.

## v1.x — queued sender decorator

Bundling [`docs/queueing.md`](queueing.md)'s "Pattern B" recipe as a
shipped class. Deferred until application demand and safe queued retry
behavior are demonstrated; no additional policy framework is required now.

## v2.0 — possible refactors

- Split the gateway's response parsing into a dedicated `ResponseParser`
  class. Today the two `parseSendResponse()` / `parseBulkResponse()`
  methods live in `TextSmsGateway`; promoting them to their own object
  would make alternative-shape support (e.g. a `ResponseParserV2` for
  a future provider API revision) cleaner.
- Drop support for the `respose-code` misspelling once the provider
  fixes their wire format consistently.

## Not on the roadmap

These are explicit non-goals:

- A first-party Africa's Talking provider. See
  [`docs/extending-providers.md`](extending-providers.md) for a how-to;
  consumers ship their own.
- Built-in `2FA` / OTP support. The package is a transport — domain
  logic belongs in consumer applications.
- A web UI for `sms_dispatches`. Operators use the artisan commands or
  Nova/Filament for their own admin panels.
