# Roadmap

Tracked work-in-progress and future enhancements. Items are grouped by
target release; nothing here is committed timeline-wise.

## v1.0 — shipped

- Provider-agnostic `SmsSender` + `SmsDeliveryReportProcessor` contracts.
- `TextSmsGateway` default concrete implementation.
- `KenyanE164Normalizer` + `ChainedNormalizer`.
- Three events: `SmsDispatchAccepted`, `SmsDispatchFailed`, `SmsDeliveryReported`.
- `SmsChannel` Laravel notification channel registered as `'sms'`.
- DLR webhook controller + `VerifyTextSmsCallback` middleware.
- `sms_dispatches` table migration (publishable stub).
- Three artisan commands: `textsms:balance`, `textsms:test`, `textsms:dispatch:show`.
- `Testing\FakeSmsGateway` with `assertSentTo()`, `assertNothingSent()`, `assertSentCount()`.
- `LogOnlySmsGateway` for local development.
- Pest + Orchestra Testbench suite (52 tests, 131 assertions).
- PHPStan level 8 clean.
- CI on PHP 8.2 + 8.3 × Laravel 12.*.

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

A `FailoverSmsSender` that wraps an ordered list of providers and falls
through on `SmsProviderException`. The interface already accommodates
this — see [`docs/extending-providers.md`](extending-providers.md) for
the documented extension recipe. Promotion to a shipped class requires
deciding the retry-policy interface.

## v1.x — HMAC-signed webhooks

TextSMS does not sign its payloads. Future providers (Twilio,
Africa's Talking) do sign with HMAC. The roadmap includes shipping a
sibling `HmacSignedMiddleware` alongside the current
`VerifyTextSmsCallback` when at least one HMAC-signing provider lands.

## v1.x — provider-side scheduled sends

TextSMS accepts a `timeToSend` parameter to queue a future send on the
provider side. The DTO currently has no scheduled-at field; adding it
would touch `SmsMessage`, `TextSmsGateway::send()`, and the bulk path.
Targeted for when at least one consumer requests it.

## v1.x — queued sender decorator

Bundling [`docs/queueing.md`](queueing.md)'s "Pattern B" recipe as a
shipped class. Deferred until the retry-policy interface (above) lands —
queued retries are where retry semantics matter most.

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
