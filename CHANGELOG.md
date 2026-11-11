# Changelog

All notable changes to `metasoftdevs/textsms-laravel-notification-sdk` are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-rc1] - 2026-11-11

Release candidate: live provider/reference confirmation is deferred by the
owner to a test Laravel application after publication. Synthetic regression
tests and application/database checks do not establish that live contract.

### Added

- Initial extraction from the production-hardened SMS gateway inside the
  Quest Pinnacle Investments Management System (QPIMS) codebase.
- Provider-agnostic `SmsSender` and `SmsDeliveryReportProcessor` contracts.
- `TextSmsGateway` as the default concrete provider against
  `https://sms.textsms.co.ke`.
- Two outbound lifecycle events: `SmsDispatchAccepted`, `SmsDispatchFailed`.
  `SmsDeliveryReported` remains loadable for compatibility but is not emitted.
- `PhoneNumberNormalizer` contract with `KenyanE164Normalizer` default and a
  `ChainedNormalizer` for multi-region consumers.
- `SmsChannel` Laravel notification channel registered as the `'sms'` driver.
- DLR webhook controller plus `VerifyTextSmsCallback` shared-secret middleware.
- `sms_dispatches` table migration (publishable PHP migration).
- Three artisan commands: `textsms:balance`, `textsms:test`, `textsms:dispatch:show`.
- `Testing\FakeSmsGateway` helper with `assertSentTo()`, `assertNothingSent()`,
  `assertSentCount()` assertions.
- Pest + Orchestra Testbench tests for the package's supported behavior.

### Fixed

- Missing TextSMS API key/partner ID now fails outbound operations before
  dispatch rows or HTTP requests. Passive callbacks do not require outbound
  credentials. Balance rejects HTTP/provider/malformed payload errors and
  preserves valid free-form objects; the command exits unsuccessfully on
  failure. The live balance success schema still requires account evidence.
- Notifiable ownership uses native morph association across all senders,
  preserving morph aliases and actual model keys.
- Fake installation invalidates already-resolved notification drivers.
  Fake/log senders validate complete batches and save them atomically before
  events; persistence failures and cancelled saves no longer claim success.
  Fake failures persist failed rows/events and retain the configured exception.
  Immediate listener/reporter failures, and log-only diagnostic logging
  failures, cannot replace saved outcomes.
- Send retries read strict cURL 6/7 evidence from native transfer statistics
  as well as guarded legacy exception context, supporting Guzzle 7 and 8
  without parsing error text. Ambiguous or response-bearing evidence remains
  ineligible for retry.

- The test command now formats its default UTC marker in UTC and reports
  unknown/local dispatch failures without claiming provider rejection. (T-20)
- Bulk sends validate all items before inserts and prepare rows atomically
  on the configured dispatch connection. Invalid later items, duplicate
  references, and invalid UTF-8 no longer strand earlier pending rows. (T-02)
- Provider response correlation rejects ambiguous references and provider
  IDs, malformed fields, contradictory envelopes, success without a usable
  message ID, and success-shaped HTTP errors. Independently valid siblings
  remain available in input order; uncertain outcomes are explicitly marked
  unknown and never described as confirmed refusals. (T-02)
- A malformed nonempty response collection no longer falls back to a
  confirmed global refusal while hiding contradictory acceptance data.
  Such responses stay unknown in single/bulk results and recovery. (T-02)
- A listener that throws during immediate event dispatch cannot change a dispatch's
  recorded outcome. Previously a throwing `SmsDispatchAccepted` listener
  turned an accepted SMS into a `failed` row; a throwing `SmsDispatchFailed`
  listener fired the event twice and erased the provider error code; and a
  throwing listener during `sendBulk()` left provider-accepted rows
  `pending` without a provider message id. (T-01)
- `sendBulk()` now attempts to record every row's outcome before any
  lifecycle event fires, then fires events only for recorded rows; one
  row's throwing listener no longer stops the remaining rows' events. (T-01)
- A failing exception reporter (for example a broken error-tracker client)
  can no longer replace a send result, a provider exception, or a DLR
  acknowledgement. (T-01 review correction W1-02)
- Delivery callbacks no longer invoke SDK events or Eloquent observers,
  including retrieval hooks. Storage failures propagate rather than being
  acknowledged as saved. (approved D3, replacing T-03)
- A storage or model-observer failure after TextSMS accepted a message is
  no longer reported as a provider failure, and the row is no longer
  rewritten as `failed`. (T-01)
- `response_description` is capped at 255 characters (the column width)
  without splitting multibyte characters. (T-01)
- The DLR webhook answers 422 instead of 500 when `messageid` or `status`
  is an array, object, or boolean, and accepts the message ids `0` and
  `"0"`. (T-05)

### Changed

- Candidate framework constraints include Laravel 12 and 13 with compatible
  Testbench/Pest toolchains. Laravel 13 requires PHP 8.3+. The prepared CI
  matrix covers twelve PHP/framework/lowest-stable combinations; execution
  evidence remains a release gate.
- Creation migrations use Laravel's native `publishesMigrations()` and a
  discoverable PHP file. Fresh schemas use a 16-character status string and
  attempt-count default 1. Publish once; existing installations need an
  application-owned forward migration, including engine-specific removal of
  legacy enum/CHECK restrictions. Re-publishing does not upgrade a table.
- Unsafe optional queued-decorator retry examples were removed. Upgrade,
  contributing and release guidance now describes passive callbacks and
  evidence-based support claims.
- **Test-helper behavior change:** `FakeSmsGateway::install()` requires a
  Laravel application; storage errors propagate. Simulated failures are sticky
  until reset and are excluded from accepted-send assertions.

- **Behaviour change (D3):** delivery callbacks store only the sanitized
  latest `dlr_payload`. Submission status, identifiers, counters and all
  timestamps stay unchanged. No delivery classification, precedence,
  `SmsDeliveryReported` emission, observer or application action runs.
  Existing delivery listeners receive no new reports; previously queued
  events are not cancelled. No schema change is needed.
- **Behaviour change (D2):** sends permit one additional Laravel HTTP
  attempt only for structured cURL 6/7 DNS/connect failures. Ambiguous
  failures and HTTP responses are not retried; redirects are disabled for
  sends. Balance retry behavior remains unchanged.
- **Behaviour change (D4):** one authenticated Laravel callback limiter
  defaults to 600/minute, configurable through `TEXTSMS_WEBHOOK_RATE_LIMIT`.
  Authentication precedes bucket use. Existing `TEXTSMS_DLR_SECRET` works
  as the callback URL's `?token=` key or `X-TextSMS-Token` header. Published
  configs/routes must be updated: retained `throttle:60,1` middleware remains
  an additional limit. See `docs/webhooks.md`.

- Delivery-report documentation distinguishes the implemented POST endpoint
  from unverified account/provider push behavior and identifies polling as
  unimplemented. Final live-contract evidence remains pending. (T-11 draft)
- The SMS driver registers when Laravel's notification manager resolves,
  without eagerly creating the manager during package boot. (T-15)
- Lifecycle events serialize dispatch identifiers with Laravel's
  `SerializesModels`. Queued listeners see the current row while event
  scalars/payload retain historical values; missing rows fail restoration.
  Outbound listeners must account for that distinction. (T-09)
- **Behaviour change:** exceptions raised during immediate gateway event dispatch are reported to
  the application's exception handler (`report()`) and are no longer
  rethrown. `send()` returns the recorded row; a provider rejection still
  throws the original `SmsProviderException`. See `docs/events.md`. (T-01,
  owner decision D1-A)
- After-commit listeners, deferred queue submission and queue workers run
  outside the gateway's listener wrapper. Their failures follow host/queue
  handling and must not trigger automatic SMS resend. (T-09 qualification)
- **Behaviour change:** `SmsProviderException` is now also thrown when the
  dispatch row cannot be prepared before sending — including metadata that
  cannot be encoded as JSON — (the message states that no request was sent)
  or cannot be updated after the provider call. In the latter case the
  message states whether TextSMS accepted the SMS, may have accepted it (no
  usable outcome, which includes any code that is not a documented
  send-rejection code), or rejected it, and says when resending is unsafe.
  `getPrevious()` holds the storage exception; recovery identifiers are
  logged at error level, best effort. No lifecycle event fires for an
  unrecorded `send()` / `sendBulk()` result. (T-01, review corrections
  W1-01, W1-02, W1-04)
- The DLR webhook rejects a malformed authenticated payload with 422 and
  one warning that lists payload keys only, and answers 401 when the token
  is not a string. (T-05)
- `pint.json` and the internal `.docs/`, `.agents/`, `.claude/` directories
  are excluded from the Composer distribution archive. (T-08A)

### Security

- The DLR shared secret (`?token=`) is no longer stored in
  `sms_dispatches.dlr_payload` or written to denial logs. Denial logs record the URL without its query
  string. **Rotate `TEXTSMS_DLR_SECRET`** in every environment that
  received callbacks before this fix; see `docs/webhooks.md`. (T-05)

[Unreleased]: https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/compare/HEAD
