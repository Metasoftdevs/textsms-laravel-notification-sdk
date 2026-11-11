# TextSMS Laravel Notification SDK Package Readiness Review

**Review date:** 2026-06-09\
**Reviewed repository:** `metasoftdevs/textsms-laravel-notification-sdk`\
**Reviewed branch:** `main`\
**Reviewed commit:** `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`\
**Benchmark:** `docs/package-development-plan.md`, sourced from the QPIMS
SMS module reflection and extraction plan.

## Executive Verdict

The package has a sound Laravel-package foundation and the planned
application-domain decoupling has largely been achieved:

- Provider-facing behavior is isolated in `TextSmsGateway`.
- The outbound and inbound contracts are separated.
- Receipt and QPIMS application logic no longer lives in package code.
- Laravel package discovery, configuration, routes, commands, migrations,
  events, Testbench tests, static analysis, and release documentation are
  present.
- The current declared quality gates pass.

The package is **not ready for a stable Packagist release**.

Before tagging `v1.0.0`, the package must resolve the state-integrity defects
and provider-contract uncertainty documented below. An internal alpha or
private release is reasonable after clearly labeling the DLR webhook as
experimental, but a public stable release would currently overstate the
reliability of delivery reconciliation.

## Review Scope

The review covered:

- Package structure and Composer metadata.
- Service-provider bindings and package discovery.
- TextSMS provider adapter behavior.
- Notification channel behavior.
- Dispatch persistence and DLR reconciliation.
- Events and consumer extension points.
- Configuration, routes, migration publishing, commands, and test helpers.
- Test suite, PHPStan, Pint, Composer validation, dependency audit, and
  lowest-supported dependency compatibility.
- Composer distribution contents and Packagist readiness.
- Provider reference documentation under
  `docs/text-sms-gateway-api-docs/`.

No production source fixes were applied during this review.

## Confirmed Strengths

### Architecture

- `SmsSender` and `SmsDeliveryReportProcessor` correctly separate outbound
  dispatch from inbound delivery reconciliation.
- `TextSmsGateway` contains provider response-shape quirks without leaking
  them into the channel or consumer application.
- `PhoneNumberNormalizer` and `ChainedNormalizer` provide a clean regional
  extension boundary.
- `SmsDispatch` is configurable by table and connection.
- Domain reconciliation is event-driven rather than coupled to QPIMS
  receipts.
- `LogOnlySmsGateway` proves a second sender implementation can exist.

### Package Integration

- Composer package discovery is correctly configured in `composer.json`.
- Config, migration, and route publish groups are registered.
- The notification channel alias is registered through Laravel's
  `ChannelManager`.
- The webhook route is guarded by a constant-time shared-secret comparison.
- Three operational Artisan commands are present.

### Verification Baseline

The following checks passed against the reviewed commit:

| Check | Result |
| --- | --- |
| `composer validate --strict` | Passed |
| `composer test` | 52 tests passed, 131 assertions |
| `composer phpstan` | Level 8, no errors |
| `composer pint-test` | Passed |
| `composer audit --locked` | No package dependency advisories |
| `composer outdated --direct --strict` | Direct dependencies current |
| Clean no-dev install from exported artifact | Passed |
| Lowest-supported dependency test | Laravel 12.0.1; 52 tests passed, 131 assertions |
| `git archive` distribution inspection | Passed; lean executable artifact |

The lowest-supported dependency graph produced advisories in old transitive
and development dependencies. The compatibility test still passed, but a
future lowest-dependency CI job must decide how advisories are handled.

## Findings

### Critical: Accepted Provider Dispatch Can Be Reclassified as Failed by a Consumer Listener

**Files:**

- `src/Gateways/TextSmsGateway.php:110`
- `src/Gateways/TextSmsGateway.php:125`
- `src/Gateways/TextSmsGateway.php:127`
- `src/Gateways/TextSmsGateway.php:164`

`SmsDispatchAccepted::dispatch()` runs inside the same broad `try` block used
to classify provider and transport failures. If any consumer listener throws
after TextSMS has accepted the SMS, the catch-all `Throwable` branch marks
the already accepted dispatch as failed and throws an `SmsProviderException`.

This corrupts the package's declared source of truth:

- `provider_message_id` remains populated.
- `sent_at` remains populated.
- `failed_at` is added.
- `status` becomes `failed`.
- A consumer listener failure is incorrectly reported as a TextSMS failure.

A temporary review-only Pest probe confirmed this behavior. The probe passed
with a row containing `provider_message_id`, `sent_at`, and `failed_at` while
its status was `failed`. The probe file was removed after verification.

**Required correction:**

- Restrict provider/transport exception handling to provider I/O and response
  persistence.
- Dispatch lifecycle events outside provider-classification catch blocks.
- Define whether consumer listener exceptions should propagate, be logged, or
  be queued, but never let them rewrite provider outcome.
- Add regression tests for throwing listeners on accepted and failed events.

### Critical: TextSMS Push-DLR Webhook Is Presented as Canonical Without a Documented Provider Contract

**Files:**

- `README.md:104`
- `docs/webhooks.md:3`
- `docs/webhooks.md:16`
- `config/textsms.php:92`
- `src/Http/Controllers/TextSmsCallbackController.php:45`
- `src/Gateways/TextSmsGateway.php:295`
- `docs/text-sms-gateway-api-docs/bulksmsUpdated.postman (1).txt:343`

The bundled TextSMS provider references document pulling delivery reports
through `/api/services/getdlr/`. They do not document:

- A provider callback HTTP method.
- A callback payload schema.
- Callback retry behavior.
- The exact delivery-status field.
- The response expected from the consumer endpoint.

The package currently presents a pushed webhook using top-level `messageid`
and `status` / `dlrstatus` fields as established provider behavior.

That assumption was already shown to be operationally uncertain in QPIMS.
A stable public package must not advertise provider-confirmed delivery
reconciliation without either a verified callback contract or a supported
polling path.

**Required correction before stable release:**

- Obtain and document the exact TextSMS callback contract, with a sanitized
  real payload fixture and a provider-confirmed method.
- Or mark push DLR as experimental and implement a supported polling
  reconciler against `/api/services/getdlr/`.
- Add exact-wire regression tests for the confirmed provider response.
- Update README, webhooks documentation, and operations guidance so they do
  not promise unsupported behavior.

### High: Bulk Preparation Is Non-Atomic and Can Leave Permanent Pending Rows

**Files:**

- `src/Gateways/TextSmsGateway.php:191`
- `src/Gateways/TextSmsGateway.php:194`
- `src/Gateways/TextSmsGateway.php:201`
- `src/Gateways/TextSmsGateway.php:222`

`sendBulk()` validates and persists each item in one loop. If a later item has
an invalid recipient, empty body, malformed shape, or invalid message type,
rows inserted for earlier items remain `pending` even though no provider HTTP
request occurs.

The contract also promises `SmsDispatchException` for malformed items, but
missing or incorrectly typed array members may instead raise PHP errors.

A temporary review-only Pest probe confirmed that a valid first item followed
by an invalid second recipient leaves one `pending` row and sends no HTTP
request.

**Required correction:**

- Validate and normalize the complete batch before inserting any rows.
- Validate array shape and `SmsMessage` / `Model` types explicitly.
- Insert prepared rows atomically or mark every prepared row terminally if
  preparation fails.
- Add regression coverage for invalid later items and malformed item shapes.

### High: DLR Reconciliation Is Not Idempotent and Can Produce Contradictory Terminal State

**Files:**

- `src/Gateways/TextSmsGateway.php:320`
- `src/Gateways/TextSmsGateway.php:322`
- `src/Gateways/TextSmsGateway.php:325`

When a delivered DLR follows a failed DLR, `status` becomes `delivered` but
`failed_at` remains populated. The reverse leaves `delivered_at` populated.
Repeated callbacks also replace terminal timestamps with the latest callback
processing time.

A temporary review-only Pest probe confirmed a `delivered` row retaining a
non-null `failed_at`.

**Required correction:**

- Define the allowed state-transition policy.
- Clear the opposite terminal timestamp when accepting a corrected terminal
  state.
- Preserve the first confirmed terminal timestamp for idempotent retries,
  unless the provider payload contains a more authoritative timestamp.
- Add repeated-callback and reversal regression tests.

### High: Planned Retry Policy Is Missing and POST Retries Are Hard-Coded

**Files:**

- `docs/package-development-plan.md:272`
- `src/Gateways/TextSmsGateway.php:366`
- `src/Gateways/TextSmsGateway.php:372`

The plan explicitly requires a `RetryPolicy` abstraction and a TextSMS policy
that does not retry permanent provider codes such as `1003`, `1004`, and
`1006`. Neither planned class exists.

All send, bulk, and balance calls currently use:

```php
->retry(2, 200, throw: false);
```

Blindly retrying SMS POST requests creates duplicate-dispatch risk when the
provider accepted the first request but its response was lost. There is no
documented TextSMS idempotency guarantee for `clientsmsid`.

**Required correction:**

- Implement the planned retry-policy abstraction.
- Retry only explicitly recoverable transport/provider conditions.
- Document ambiguous-timeout and idempotency behavior.
- Add tests proving permanent validation, credential, and credit errors are
  not retried.

### High: Migration Publishing Does Not Use Laravel's Migration Publishing API

**Files:**

- `src/TextSmsNotificationServiceProvider.php:178`
- `src/TextSmsNotificationServiceProvider.php:179`

The package manually calls `publishes()` with a destination filename based on
the current timestamp. Re-running the publish command in a later process can
produce another migration filename and duplicate schema creation.

Laravel 12 provides `publishesMigrations()`, which is specifically intended
for package migrations and automatically updates migration timestamps.

**Required correction:**

- Replace the manual dynamic destination with `publishesMigrations()`.
- Add a package integration test proving migration publication is
  repeat-safe.

Reference:
<https://laravel.com/docs/12.x/packages#migrations>

### High: Public Packagist Publication Is Currently Blocked by Repository Visibility and Missing Release Tags

**Observed on 2026-06-09:**

- The unauthenticated public GitHub URL returned `404`.
- The connected GitHub application could not access the repository.
- `git ls-remote --tags origin` returned no tags.
- No Packagist package named
  `metasoftdevs/textsms-laravel-notification-sdk` was found.
- The `metasoftdevs` vendor namespace already exists on Packagist.

Packagist public submission requires a public repository URL, and versions
are derived from VCS tags.

**Required correction:**

- Make the repository public before public Packagist submission.
- Confirm the publishing Packagist account is an authorized maintainer of the
  protected `metasoftdevs` vendor namespace.
- Resolve code blockers, create a release-candidate tag, smoke install it,
  then create the stable tag.

Reference:
<https://packagist.org/about#how-to-submit-packages>

### Medium: Webhook Denial Logging Can Persist Secrets

**Files:**

- `src/Http/Middleware/VerifyTextSmsCallback.php:52`
- `src/Http/Middleware/VerifyTextSmsCallback.php:54`
- `src/Http/Middleware/VerifyTextSmsCallback.php:83`
- `src/Http/Middleware/VerifyTextSmsCallback.php:87`

The middleware accepts a `?token=` query secret and logs
`$request->fullUrl()` on denial. During secret rotation, a stale but still
sensitive portal token will be written into application logs.

Query-string secrets may also appear in reverse-proxy and access logs.

**Required correction:**

- Redact `token` before logging the URL.
- Prefer the header form wherever the provider permits it.
- Document query-token exposure and rotation requirements.

### Medium: Malformed Callback Documentation Promises Logging That Does Not Occur

**Files:**

- `src/Http/Controllers/TextSmsCallbackController.php:21`
- `src/Http/Controllers/TextSmsCallbackController.php:49`

The controller documentation says missing-message-ID payloads leave a loud
trail in operations logs. The implementation returns `422` but does not log
the payload or failure.

This is especially relevant while the provider callback schema is
unconfirmed.

**Required correction:**

- Add sanitized malformed-payload logging.
- Do not log credentials, callback tokens, or full sensitive message bodies.
- Add a regression test asserting the warning is emitted.

### Medium: Event Classes Are Not Prepared for Queued Listeners

**Files:**

- `src/Events/SmsDispatchAccepted.php`
- `src/Events/SmsDispatchFailed.php`
- `src/Events/SmsDeliveryReported.php`

The package encourages consumers to register domain listeners, and those
listeners may be queued. The events carry an Eloquent `SmsDispatch` model but
do not use Laravel's `SerializesModels` trait.

Laravel's event guidance uses `SerializesModels` so Eloquent models are
gracefully serialized for queued listeners.

**Required correction:**

- Add and test `SerializesModels`, or explicitly document that listeners must
  remain synchronous.
- Add a queued-listener serialization test.

Reference:
<https://laravel.com/docs/12.x/events#defining-events>

### Medium: Balance Lookup Does Not Classify Provider or HTTP Failure

**Files:**

- `src/Gateways/TextSmsGateway.php:345`
- `src/Gateways/TextSmsGateway.php:353`
- `src/Console/Commands/BalanceCommand.php`

`balance()` returns any decoded payload without checking HTTP status or
provider response code. A provider error payload can therefore be printed as
an account balance and the command can exit successfully.

**Required correction:**

- Validate HTTP and provider response shape.
- Throw `SmsProviderException` for provider and transport failures.
- Add error-shape and unparseable-response tests.

### Medium: Test Helper Can Hide Broken Consumer Setup

**Files:**

- `src/Testing/FakeSmsGateway.php:64`
- `src/Testing/FakeSmsGateway.php:68`
- `src/Testing/FakeSmsGateway.php:104`
- `src/Testing/FakeSmsGateway.php:108`
- `src/Testing/FakeSmsGateway.php:139`

`FakeSmsGateway::install()` silently succeeds when no facade application
exists. `send()` catches every persistence exception and converts the row
into an in-memory model. This can let consumer tests pass when the migration,
database connection, or schema is broken.

The fake also implements `SmsDeliveryReportProcessor` but always returns
`null`, so it cannot exercise the package's advertised listener-side DLR
reconciliation path.

**Required correction:**

- Fail clearly when installation occurs without a Laravel application.
- Make persistence fallback explicit and opt-in.
- Provide a delivery-report simulation helper that updates a recorded
  dispatch and fires `SmsDeliveryReported`.
- Test the helper itself.

### Medium: Permanent Test Suite Does Not Cover Several Public Package Surfaces

Current tests cover the primary TextSMS gateway, webhook, notification
channel, and Kenyan normalizer. They do not cover:

- `TextSmsNotificationServiceProvider` bindings and publish groups.
- Repeated migration publication.
- `LogOnlySmsGateway`.
- `FakeSmsGateway` assertions, persistence fallback, or DLR behavior.
- `BalanceCommand`, `TestSendCommand`, or `ShowDispatchCommand`.
- `ChainedNormalizer`.
- Throwing event listeners.
- Queued event listeners.
- Bulk preflight failures.
- DLR idempotency and reversals.

The CI matrix also tests only latest-resolved dependencies. A manual
`--prefer-lowest` run passed against Laravel 12.0.1, but that protection is
not automated.

**Required correction:**

- Add focused tests for each public surface.
- Add a lowest-supported dependency CI job.
- Add `composer validate --strict` and `composer audit` CI jobs.

### Low: Planned Public/Protected Method PHPDoc Standard Is Not Fully Met

The implementation plan requires useful PHPDoc for every public/protected
method. Several public methods rely only on interface or class documentation,
including methods in `TextSmsGateway`, `LogOnlySmsGateway`,
`FakeSmsGateway`, and the normalizers.

This does not affect runtime correctness, but it is a direct plan-conformance
gap for a package whose public API is meant for external consumers.

### Low: Distribution Hygiene Has Minor Inconsistencies

- `.gitattributes` excludes a non-existent `.php-cs-fixer.dist.php` but
  includes `pint.json` in the consumer distribution.
- `composer archive` timed out twice in the current Windows environment.
  `git archive` completed successfully and produced a lean artifact.
- The current local Composer executable is `2.7.6`; `composer diagnose`
  reports it as outdated and vulnerable. This is a release-toolchain issue,
  not a package dependency issue.

Before release, update Composer and rerun the archive inspection.

## Plan Conformance Summary

| Planned area | Status | Review |
| --- | --- | --- |
| Provider-agnostic contracts | Implemented | Sound |
| Receipt/application decoupling | Implemented | Sound |
| PSR-3 logging and lifecycle events | Implemented | Listener-failure isolation required |
| Pluggable phone normalizer | Implemented | Add `ChainedNormalizer` tests |
| Package namespace and discovery | Implemented | Sound |
| Log-only second provider | Implemented | Missing tests |
| Split inbound/outbound contracts | Implemented | Sound |
| Retry-policy abstraction | **Not implemented** | Release blocker |
| Artisan operations commands | Implemented | Missing tests |
| Published fake gateway | Implemented | Fidelity concerns |
| ULID client IDs | Implemented | Sound |
| Publishable config/routes/migration | Implemented | Migration publication method must change |
| 50+ tests | Implemented | 52 tests / 131 assertions |
| PHPStan level 8 | Implemented | Passing |
| PHP 8.2 / 8.3 CI | Implemented | Add lowest-dependency job |
| Stable + development Laravel matrix | Partial | No dev/current-development matrix |
| Real provider DLR smoke | Not proven | Provider callback contract unresolved |
| Packagist-ready public release | Not ready | Repository visibility, tags, and blockers |

## Recommended Release Gates

### Required Before Any Public Release Candidate

1. Prevent listener failures from rewriting provider outcome.
2. Make bulk preflight atomic.
3. Make DLR reconciliation idempotent and state-consistent.
4. Resolve or explicitly downgrade the push-DLR webhook contract.
5. Implement the planned retry policy.
6. Use Laravel's migration publishing API.
7. Redact callback tokens from logs.
8. Add regression tests for every corrected behavior.

### Required Before Stable `v1.0.0`

1. Complete the publication checklist in
   `.docs/dev/reviews/packagist-publication-checklist.md`.
2. Make the repository publicly accessible.
3. Confirm all GitHub Actions checks pass.
4. Run a fresh Laravel 12 install smoke test from the release-candidate tag.
5. Run one authorized live TextSMS send.
6. Confirm delivery reconciliation using a provider-supported mechanism.
7. Update `CHANGELOG.md` with a versioned `v1.0.0` section.
8. Tag only the exact reviewed commit.

## Sources

- Laravel 12 package development:
  <https://laravel.com/docs/12.x/packages>
- Laravel 12 events and queued-listener model serialization:
  <https://laravel.com/docs/12.x/events>
- Composer validation and archive commands:
  <https://getcomposer.org/doc/03-cli.md>
- Composer library versioning:
  <https://getcomposer.org/doc/02-libraries.md>
- Packagist submission, version tags, and update hooks:
  <https://packagist.org/about>
