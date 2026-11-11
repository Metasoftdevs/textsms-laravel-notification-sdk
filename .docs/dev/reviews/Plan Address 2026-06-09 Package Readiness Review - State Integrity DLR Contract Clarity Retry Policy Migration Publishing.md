# Plan: Address 2026-06-09 Package Readiness Review — State Integrity, DLR Contract Clarity, Retry Policy, Migration Publishing, and Test Coverage

## Context

The package `metasoftdevs/textsms-laravel-notification-sdk` shipped its
v1.0 RC code yesterday (commit `e1db5df` → `f6b7627`). An independent
package-readiness review found **13 issues** that **block a stable
Packagist v1.0.0 tag**. Test suite, PHPStan, Pint, Composer audit, and
lowest-dependency install all currently pass — but the review's
test-only Pest probes proved three real state-corruption bugs in the
gateway, plus a fourth class of issue around the inferred (not
contract-guaranteed) TextSMS push-DLR payload shape.

Review file:
`C:\Users\Peter Maina\Desktop\projects\LaravelPackagesDevelopment\textsms-laravel-notification-sdk\.docs\dev\reviews\2026-06-09-package-readiness-review.md`

Independent assessment per finding:

| # | Severity | Finding | My assessment | Triage |
|---|---|---|---|---|
| 1 | Critical | Listener exception inside `send()` try-block reclassifies accepted dispatch as `failed` | **Confirmed real.** `SmsDispatchAccepted::dispatch()` runs inside the same `try` that catches the generic `Throwable` branch — any listener throwing will trigger `markFailed()` on an already-accepted row. | RC blocker |
| 2 | Critical | Push-DLR webhook presented as canonical without provider-documented contract | **Confirmed valid concern, partially mitigated.** QPIMS production observed `messageid` + `status` keys empirically, but the TextSMS API docs only document the pull path (`/getdlr/`). For a public package this is asymmetric — must either document the push schema as observed-not-guaranteed AND ship a polling reconciler against `/getdlr/`. | RC blocker (doc + polling reconciler) |
| 3 | High | `sendBulk()` non-atomic preflight — earlier items left as `pending` if a later item fails validation | **Confirmed real.** Loop pre-inserts each row as it validates; a validation failure mid-loop strands earlier rows. Also raises `TypeError` (not `SmsDispatchException`) when `$item['message']` is missing or not an `SmsMessage`. | RC blocker |
| 4 | High | DLR reconciliation not idempotent — opposite terminal timestamp not cleared on state reversal | **Confirmed real.** `delivered` → `failed` (or vice versa) DLRs leave a contradictory row with both `delivered_at` AND `failed_at` populated. | RC blocker |
| 5 | High | `Http::retry(2, 200, throw: false)` hard-coded — risks duplicate dispatch since TextSMS has no documented `clientsmsid` idempotency guarantee | **Confirmed real.** Originally listed in the plan as a deferral; never implemented. Blind retry on `Http::post()` for `sendsms` can charge + send twice if the first request succeeded server-side but the response was lost. | RC blocker |
| 6 | High | Migration publishing uses manual `date()`-timestamped destination instead of Laravel 12's `publishesMigrations()` API | **Confirmed real.** Manual destination is computed at provider boot; re-publishing in a later process produces a different filename, potentially duplicating schema creation. | RC blocker |
| 7 | High | Packagist publication blocked by private repo / missing tags | **Operational.** The user pushed to `Metasoftdevs/textsms-laravel-notification-sdk` today; this resolves the moment the repo is set public AND a tag exists. Not a code change. | Release gate, not code |
| 8 | Medium | Webhook denial logging persists `?token=` secrets via `$request->fullUrl()` | **Confirmed real.** Even on rotation, old tokens get archived in app logs. | RC blocker |
| 9 | Medium | Controller docstring promises malformed-payload logging that never executes | **Confirmed real.** 422 path is silent. | RC fix |
| 10 | Medium | Three events lack `SerializesModels` — queued listeners would serialize the entire Eloquent model object | **Confirmed real.** Per Laravel 12 event docs. | RC fix |
| 11 | Medium | `balance()` returns provider-error payloads as if they were balance data | **Confirmed real.** If TextSMS returns `{response-code: 1006}` at HTTP 200, the balance command exits successfully with a misleading "balance". | RC fix |
| 12 | Medium | `FakeSmsGateway::install()` silently no-ops without an app; `send()` masks persistence failures; `recordDeliveryReport()` always returns null | **Confirmed real.** Hides broken consumer setups. Also can't exercise DLR listener path. | RC fix |
| 13 | Medium / Low | Test coverage gaps + per-method PHPDoc + `.gitattributes` hygiene | **Confirmed valid.** Several will land as a natural side-effect of fixing items 1–12. The rest are polish. | Pre-v1.0.0 |

The plan groups the fixes into four execution streams, with stream A
being the release blockers and stream D being polish. Every fix lands
with at least one regression test.

---

## Stream A — State-integrity correctness (RC1 blockers)

### A.1 — Isolate listener failures from provider classification

**Files:** `src/Gateways/TextSmsGateway.php` (`send()` at lines ~85–155;
`sendBulk()` at lines ~190–280; `recordDeliveryReport()` at lines
~285–340).

**Pattern across all three methods:** today, the lifecycle event is
fired *inside* the try block. Move it *outside* so a throwing listener
cannot rewrite provider-classified state.

Concrete shape for `send()`:

```php
public function send(...): SmsDispatch
{
    $normalised = $this->requireValidRecipient($recipient);
    $body = $this->requireNonEmptyBody($message->body);
    // ... pre-insert ...

    // Two-stage outcome: stage 1 classifies + persists, stage 2 emits the event.
    [$outcome, $eventArgs] = $this->classifyAndPersist($dispatch, $normalised, $body, $senderId, $clientId);
    // → $outcome is 'accepted' | throws SmsProviderException

    SmsDispatchAccepted::dispatch($dispatch->refresh());

    return $dispatch->refresh();
}
```

…where `classifyAndPersist()` contains the existing try/catch/parse
logic, but instead of dispatching the event inside the catch, it
**either returns** `['accepted', null]` **or throws after** firing
`SmsDispatchFailed` and persisting the failure. The
`SmsDispatchFailed::dispatch()` call inside the catch is acceptable —
if a listener throws *there*, the original `SmsProviderException` was
already in flight and gets replaced; we mitigate by wrapping
`SmsDispatchFailed::dispatch()` in its own try-catch that logs
listener errors but does not rethrow.

Same pattern is applied to:
- `sendBulk()` (per-row accepted event after the loop completes)
- `recordDeliveryReport()` (`SmsDeliveryReported::dispatch()` moved
  outside the persistence transaction)

**Tests** (`tests/Feature/TextSmsGatewayTest.php`):
- `it('does not mark an accepted dispatch as failed when an SmsDispatchAccepted listener throws')`
- `it('still throws SmsProviderException when a failure listener throws')` (asserts the original provider exception is preserved, listener error is logged)
- `it('does not corrupt DLR row state when an SmsDeliveryReported listener throws')`

### A.2 — Atomic `sendBulk()` preflight

**Files:** `src/Gateways/TextSmsGateway.php` (`sendBulk()` lines
~190–280).

Refactor to two passes:

**Pass 1 — validate-only.** Iterate `$items` and call a new private
`validateBulkItems(array $items): array` that:
- Asserts `count($items) <= self::BULK_LIMIT` (already done).
- For each item: asserts `is_array($item)`, asserts `isset($item['recipient'])` is a string, asserts `isset($item['message'])` is an `SmsMessage` instance, asserts `isset($item['notifiable'])` is null-or-`Model`.
- For each item: calls `requireValidRecipient()` and `requireNonEmptyBody()`.
- Returns the prepared per-item array: `[normalised, body, senderId, clientId, notifiable, metadata]`.
- On any failure: throws `SmsDispatchException` with a message identifying the offending item index. **No rows inserted yet.**

**Pass 2 — insert + send.** Only if Pass 1 succeeded, iterate the
prepared array, `preInsert()` every row, build `$smslist`, POST.

**Tests:**
- `it('inserts no rows when a later bulk item has an invalid recipient')`
- `it('throws SmsDispatchException with the offending item index for malformed array shapes')`
- `it('throws SmsDispatchException when message is not an SmsMessage instance')`

### A.3 — Idempotent DLR reconciliation

**Files:** `src/Gateways/TextSmsGateway.php` (`recordDeliveryReport()`
lines ~285–340).

Define and apply the state-transition policy:

| Current → DLR | Action |
|---|---|
| any → `delivered` | `status='delivered'`, set `delivered_at` (only if not already set), **clear `failed_at`**. |
| any → `failed` | `status='failed'`, set `failed_at` (only if not already set), **clear `delivered_at`**. |
| `delivered`/`failed` → same kind | No-op on timestamps (preserve first confirmed time), still persist `dlr_payload`, still fire event. |
| any → `unknown` | Persist `dlr_payload` only, no status change. |

Implementation: a private `applyDeliveryStatePolicy(SmsDispatch $dispatch, string $deliveryStatus): array` that returns the right
column updates given the current row state.

**Tests:**
- `it('clears failed_at when a delivered DLR follows a failed DLR for the same row')`
- `it('clears delivered_at when a failed DLR follows a delivered DLR for the same row')`
- `it('preserves the first delivered_at when a second delivered DLR arrives')`
- `it('preserves the first failed_at when a second failed DLR arrives')`
- `it('does not change status on an unknown DLR but still persists dlr_payload and fires the event')`

### A.4 — Retry policy abstraction

**New files:**
- `src/Contracts/RetryPolicy.php` — interface (`shouldRetry(Throwable $e, int $attempt): bool`, `delayMs(int $attempt): int`).
- `src/RetryPolicies/TextSmsRetryPolicy.php` — default; only retries `ConnectionException` (transport-level disconnect, no provider acknowledgement), never `RequestException` (means we got a response), never `SmsProviderException`. Cap at 2 attempts, exponential backoff 200ms/400ms.
- `src/RetryPolicies/NoRetryPolicy.php` — opt-out for callers that want zero retries.

**Edits to `src/Gateways/TextSmsGateway.php`:**
- Constructor takes `RetryPolicy $retryPolicy`.
- Replace `->retry(2, 200, throw: false)` on `send()`/`sendBulk()` paths with a manual retry loop that consults `$this->retryPolicy`.
- Keep `->retry(2, 200, throw: false)` on `balance()` (idempotent) but route through the same policy for consistency.

**Edits to `src/TextSmsNotificationServiceProvider.php`:**
- Bind `RetryPolicy::class` to `config('textsms.retry_policy', TextSmsRetryPolicy::class)`.
- Pass into the gateway constructor.

**Edits to `config/textsms.php`:**
- New `'retry_policy' => TextSmsRetryPolicy::class` key (documented).

**Tests** (`tests/Feature/TextSmsGatewayTest.php` + new `tests/Unit/TextSmsRetryPolicyTest.php`):
- `it('does not retry on permanent provider codes 1003/1004/1006')` (asserts exactly 1 HTTP call via `Http::assertSentCount(1)`).
- `it('retries on ConnectionException up to the policy limit')`.
- `it('does not retry on RequestException because we received a response')`.

---

## Stream B — Provider DLR contract clarity (RC1 blocker)

### B.1 — Re-frame the push-DLR webhook as observed-not-guaranteed

**Files:** `README.md`, `docs/webhooks.md`, `config/textsms.php`,
`src/Http/Controllers/TextSmsCallbackController.php` (docblock).

Reword to:

> **Status:** the package supports two delivery-reconciliation paths.
> 1. **Push webhook** (default). TextSMS posts a delivery report to
>    `POST {prefix}/textsms/dlr` when configured on their portal. The
>    payload shape (`messageid`, `mobile`, `status`) is **observed
>    empirically in production deployments** of this package, **not
>    guaranteed by the TextSMS API contract**. If TextSMS changes their
>    callback shape, push DLR will degrade gracefully (unknown statuses
>    persist the payload without flipping `status`) but the official
>    reconciliation path is option 2.
> 2. **Pull poller** (recommended for new integrations).
>    `TextSmsPollingDlrReconciler` calls TextSMS's documented
>    `POST /api/services/getdlr/` endpoint and applies the same
>    state-transition policy. Schedule it via `Kernel::schedule()`.

### B.2 — Ship the polling reconciler

**New files:**
- `src/Dlr/TextSmsPollingDlrReconciler.php` — calls
  `/api/services/getdlr/` for any `sms_dispatches` row where
  `provider='textsms'`, `status='sent'`, `delivered_at IS NULL`,
  `sent_at > now() - configurable lookback`. Applies the same state-
  transition policy from A.3. Fires `SmsDeliveryReported`.
- `src/Console/Commands/PollDlrCommand.php` —
  `php artisan textsms:dlr:poll` so consumers can run it ad-hoc or
  through `Schedule::call(...)`.

**Edits:**
- `docs/webhooks.md` — append a "Polling alternative" section with
  scheduling example.
- `config/textsms.php` — add `'dlr_polling' => ['lookback_minutes' => 60, 'batch_size' => 100]`.

**Tests** (`tests/Feature/TextSmsPollingDlrReconcilerTest.php`):
- `it('polls /getdlr/ for awaiting-DLR rows and flips delivered ones')`
- `it('respects lookback_minutes and ignores rows outside the window')`
- `it('handles the documented getdlr response shape verbatim per the Postman fixture')`
- `it('fires SmsDeliveryReported with deliveryStatus=delivered when poll returns success')`

The Postman fixture under
`docs/text-sms-gateway-api-docs/bulksmsUpdated.postman (1).txt`
documents the `/getdlr/` request shape. The response shape is what we
verify by running one authorised call on the existing `07XXXXXXXX`
test recipient — same as the live smoke from the v1.0 work — and
recording the exact payload in a fixture committed to the repo.

---

## Stream C — Package-mechanics correctness (RC1 fixes)

### C.1 — Use `publishesMigrations()`

**Files:** `src/TextSmsNotificationServiceProvider.php`
(`registerPublishables()` lines ~157–174).

Rename the file from
`database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub`
to `database/migrations/2026_06_08_000000_create_sms_dispatches_table.php`
(Laravel's `publishesMigrations()` re-timestamps `.php` files on
publish), and replace the manual block with:

```php
$this->publishesMigrations([
    __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
], 'textsms-migrations');
```

Update `tests/TestCase.php` — drop the manual stub-loader, replace
with `$this->loadMigrationsFrom(__DIR__.'/../database/migrations')`
which now finds the renamed `.php` file natively.

**Tests** (new `tests/Feature/MigrationPublishingTest.php`):
- `it('publishes the migration with a fresh timestamp on each invocation')`
- `it('does not duplicate the migration when published twice within the same process')`

### C.2 — Redact `?token=` before logging

**Files:** `src/Http/Middleware/VerifyTextSmsCallback.php`
(`logDenial()` lines ~52–88).

Replace `$request->fullUrl()` with a sanitised URL:

```php
private function sanitisedUrl(Request $request): string
{
    $query = collect($request->query())
        ->map(fn ($v, $k) => $k === 'token' ? '[REDACTED]' : $v)
        ->all();

    $queryString = $query === [] ? '' : '?'.http_build_query($query);

    return $request->url().$queryString;
}
```

Use that in the log context.

**Tests** (`tests/Feature/WebhookTest.php` — extend the existing 8
cases):
- `it('redacts the token query parameter from denial logs')` — uses a
  test PSR-3 logger captured via `Log::spy()`.

### C.3 — Log malformed-payload 422s in the controller

**Files:** `src/Http/Controllers/TextSmsCallbackController.php` (line ~45).

Inject `LoggerInterface`, emit a `warning` before returning 422 with
the same shape the docstring promises (request body excluded —
sanitised).

**Tests:**
- `it('logs a warning when the dlr endpoint is hit without a messageid')`

### C.4 — Add `SerializesModels` to the three events

**Files:** `src/Events/SmsDispatchAccepted.php`,
`src/Events/SmsDispatchFailed.php`,
`src/Events/SmsDeliveryReported.php`.

Each gains:

```php
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SmsDispatchAccepted
{
    use Dispatchable, SerializesModels;
    // ...
}
```

**Tests** (`tests/Feature/EventSerializationTest.php`):
- `it('serializes SmsDispatchAccepted for a queued listener')`
- `it('serializes SmsDispatchFailed including providerErrors')`
- `it('serializes SmsDeliveryReported including the raw payload array')`

Each test verifies a queued-listener round trip using a fake queue
that captures the serialised job, then unserialise + assert the
`SmsDispatch` is fetched-from-DB rather than hydrated from the raw
payload.

### C.5 — Classify `balance()` failures

**Files:** `src/Gateways/TextSmsGateway.php` (`balance()` ~345–365).

Apply the existing two-shape parser: HTTP 200 with non-null
`response-code` at the top level → throw `SmsProviderException` with
the provider's code/description. Unparseable response → throw with
diagnostic stub.

**Tests:**
- `it('throws SmsProviderException when balance returns an error payload at HTTP 200')`
- `it('throws SmsProviderException when balance returns a 5xx with no JSON')`

### C.6 — `FakeSmsGateway` honesty

**Files:** `src/Testing/FakeSmsGateway.php`.

Three changes:

1. `install()` — throw `RuntimeException` if `Facade::getFacadeApplication()` is null, with a message pointing to Pest's `uses(TestCase::class)`.
2. `send()` — remove the swallow-everything `catch (\Throwable)`. Let DB exceptions propagate. Add a separate `installWithoutPersistence(): self` static factory for the rare in-memory-only use case.
3. Add `simulateDelivery(SmsDispatch $row, string $status = 'Delivered'): SmsDispatch` and `simulateFailure(SmsDispatch $row, string $status = 'Failed'): SmsDispatch` helpers. Each persists the right column updates and fires `SmsDeliveryReported`.

**Tests** (new `tests/Feature/FakeSmsGatewayTest.php`):
- `it('throws when install() is called without a Laravel application')`
- `it('does not swallow database exceptions during send()')`
- `it('simulateDelivery updates the dispatch row and fires SmsDeliveryReported')`
- `it('simulateFailure updates the dispatch row and fires SmsDeliveryReported')`
- `it('assertSentTo and assertSentCount pass on happy-path sends')`
- `it('assertNothingSent passes when no sends occurred')`

---

## Stream D — Test coverage + polish (pre-v1.0.0)

### D.1 — Service-provider integration tests

**New file:** `tests/Feature/ServiceProviderTest.php`.

Cases:
- `it('binds SmsSender to the configured default_sender')`
- `it('binds SmsDeliveryReportProcessor to the same instance as SmsSender when the sender implements both contracts')`
- `it('throws when the configured default_sender does not also implement SmsDeliveryReportProcessor and the webhook is enabled')`
- `it('registers the verify.textsms.callback middleware alias')`
- `it('loads routes/webhooks.php only when config webhook.enabled is true')`
- `it('registers the three artisan commands only in CLI context')`

### D.2 — `LogOnlySmsGateway` tests

**New file:** `tests/Feature/LogOnlySmsGatewayTest.php`.

Cases mirror the `TextSmsGateway` happy path + bulk + DLR-noop but
without `Http::fake()` (the log-only gateway never makes HTTP calls).
Verifies `SmsDispatchAccepted` still fires and rows are persisted with
`provider='log-only'`.

### D.3 — Artisan command tests

**New file:** `tests/Feature/ArtisanCommandsTest.php`.

Cases:
- `php artisan textsms:balance` happy path
- `php artisan textsms:balance` on `SmsProviderException`
- `php artisan textsms:test 0712345678` with `Http::fake()` accepted
- `php artisan textsms:test invalid-phone` returns `INVALID`
- `php artisan textsms:dispatch:show {ulid}` for an existing row
- `php artisan textsms:dispatch:show {missing-ulid}` returns `FAILURE`

### D.4 — `ChainedNormalizer` tests

**New file:** `tests/Unit/ChainedNormalizerTest.php`.

Dataset-driven cases proving first-match-wins semantics across a
chain of `[KenyanE164Normalizer, FakeUgandanNormalizer]`.

### D.5 — Per-method PHPDoc (Low 1)

Add `@param` / `@return` / `@throws` to every public method on:
`TextSmsGateway`, `LogOnlySmsGateway`, `FakeSmsGateway`,
`KenyanE164Normalizer`, `ChainedNormalizer`, the three artisan
commands, the controller, and the middleware. Class-level docblocks
already exist; this is per-method.

Verified by running `vendor/bin/phpstan analyse` (level 8 already
flags missing `@param` for `array` parameters; promote PHPDoc
strictness in `phpstan.neon.dist` if necessary).

### D.6 — `.gitattributes` hygiene (Low 2)

Remove the line referencing the non-existent
`.php-cs-fixer.dist.php`. Add `/pint.json export-ignore` so the
consumer dist tarball stays lean.

### D.7 — CI: lowest-supported-dependency job

**Files:** `.github/workflows/tests.yml`.

Add a matrix entry `stability: [prefer-stable, prefer-lowest]` so CI
catches dependency-graph regressions automatically.

### D.8 — CI: `composer validate --strict` + `composer audit`

**Files:** `.github/workflows/static-analysis.yml`.

Add two new jobs running `composer validate --strict` and
`composer audit --locked` on every push and PR.

---

## Execution order (PRs in this sequence)

Each step in this list is one PR — small, reviewable, ships in order
of dependency:

1. **PR 1 (Stream A.1):** Listener isolation in `send()`, `sendBulk()`, `recordDeliveryReport()` + 3 regression tests.
2. **PR 2 (Stream A.2):** Atomic bulk preflight + 3 regression tests.
3. **PR 3 (Stream A.3):** Idempotent DLR state transitions + 5 regression tests.
4. **PR 4 (Stream A.4):** `RetryPolicy` contract + `TextSmsRetryPolicy` + 3 regression tests.
5. **PR 5 (Stream B.1):** Doc re-framing of push DLR as observed-not-guaranteed.
6. **PR 6 (Stream B.2):** `TextSmsPollingDlrReconciler` + `textsms:dlr:poll` artisan command + 4 tests + Postman fixture from one authorised live call.
7. **PR 7 (Stream C.1):** Migration publishing via `publishesMigrations()` + 2 tests + TestCase refactor.
8. **PR 8 (Stream C.2):** Token redaction in webhook denial logs + 1 test.
9. **PR 9 (Stream C.3):** Controller logs malformed 422s + 1 test.
10. **PR 10 (Stream C.4):** `SerializesModels` on three events + 3 tests.
11. **PR 11 (Stream C.5):** Classify `balance()` failures + 2 tests.
12. **PR 12 (Stream C.6):** `FakeSmsGateway` hardening + 6 tests.
13. **PR 13 (Stream D.1):** Service-provider integration tests.
14. **PR 14 (Stream D.2):** `LogOnlySmsGateway` tests.
15. **PR 15 (Stream D.3):** Artisan command tests.
16. **PR 16 (Stream D.4):** `ChainedNormalizer` tests.
17. **PR 17 (Stream D.5):** Per-method PHPDoc pass.
18. **PR 18 (Stream D.6 + D.7 + D.8):** `.gitattributes` hygiene + lowest-dependency CI job + composer-validate / composer-audit CI jobs.

Tag `v1.0.0-rc1` after PR 12 (all RC blockers closed).
Tag `v1.0.0` after PR 18 + one final live-DLR smoke + one
unauthorised-test push to a throwaway Laravel 12 project from
`v1.0.0-rc1`.

---

## Verification

End-to-end acceptance for the v1.0.0 release:

| Check | Command / artefact |
|---|---|
| Pest | `vendor/bin/pest` — target **90+ tests**, **all green** (from current 52). |
| PHPStan level 8 | `vendor/bin/phpstan analyse` — clean. |
| Pint | `vendor/bin/pint --test` — clean. |
| Composer validate | `composer validate --strict` — clean. |
| Composer audit | `composer audit --locked` — clean. |
| Lowest-dep install | CI matrix `stability: prefer-lowest` — green. |
| Listener-isolation behaviour | New regression tests assert that throwing listeners do NOT corrupt provider-classified state. |
| Bulk atomicity | New regression tests assert that a single invalid item leaves zero rows. |
| DLR idempotency | New regression tests assert that reversals + repeats produce one consistent terminal state. |
| Retry policy | New regression tests assert no retry on `1003`/`1004`/`1006`. |
| Migration publish idempotency | New `MigrationPublishingTest` proves repeated publishes don't duplicate. |
| Token redaction | New webhook test asserts logged URL contains `[REDACTED]`, not the real token. |
| Queued-listener serialisation | New event test asserts the three events round-trip through a fake queue. |
| Balance classification | New balance test asserts `SmsProviderException` is thrown on 1006-at-200. |
| FakeSmsGateway honesty | New helper tests assert `install()` throws without an app, and `simulateDelivery()` fires the event. |
| Polling reconciler | Manual smoke: `php artisan textsms:dlr:poll` against the `07XXXXXXXX` test recipient — confirm a sent row flips to `delivered`. |
| Push-DLR behaviour | Smoke (after PR 12): configure a portal callback URL pointing at a throwaway Laravel 12 install, send one SMS via `textsms:test`, wait for the real DLR, confirm `sms_dispatches.status` flips and `SmsDeliveryReported` fires. |
| Repository public | GitHub repo page accessible unauthenticated. |
| Packagist | Package listed at `packagist.org/packages/metasoftdevs/textsms-laravel-notification-sdk`. |

---

## Critical files (authoritative list for the porting work)

```
src/
  Gateways/TextSmsGateway.php                  ← Streams A.1, A.2, A.3, A.4, C.5
  Contracts/RetryPolicy.php                    ← NEW (A.4)
  RetryPolicies/TextSmsRetryPolicy.php         ← NEW (A.4)
  RetryPolicies/NoRetryPolicy.php              ← NEW (A.4)
  Dlr/TextSmsPollingDlrReconciler.php          ← NEW (B.2)
  Console/Commands/PollDlrCommand.php          ← NEW (B.2)
  TextSmsNotificationServiceProvider.php       ← Streams A.4, B.2, C.1
  Http/Middleware/VerifyTextSmsCallback.php    ← Stream C.2
  Http/Controllers/TextSmsCallbackController.php ← Stream C.3
  Events/SmsDispatchAccepted.php               ← Stream C.4
  Events/SmsDispatchFailed.php                 ← Stream C.4
  Events/SmsDeliveryReported.php               ← Stream C.4
  Testing/FakeSmsGateway.php                   ← Stream C.6

config/textsms.php                             ← Streams A.4, B.2
database/migrations/2026_06_08_000000_create_sms_dispatches_table.php
                                               ← Stream C.1 (rename from .stub)

README.md                                      ← Stream B.1
docs/webhooks.md                               ← Streams B.1, B.2
docs/architecture.md                           ← Stream B.1 (note polling option)
docs/operations.md                             ← Stream B.2 (scheduling guidance)
docs/roadmap.md                                ← Strike "HMAC-signed webhooks" and "Polling reconciler" from deferred — both now in v1.0
CHANGELOG.md                                   ← Document fixes under [Unreleased]

.gitattributes                                 ← Stream D.6
.github/workflows/tests.yml                    ← Stream D.7
.github/workflows/static-analysis.yml          ← Stream D.8
tests/TestCase.php                             ← Stream C.1 (migration loader refactor)

tests/Feature/TextSmsGatewayTest.php           ← Extended with listener-isolation, bulk-atomicity, DLR-idempotency, retry-policy cases
tests/Feature/WebhookTest.php                  ← Extended with token-redaction + malformed-payload-log cases
tests/Feature/MigrationPublishingTest.php      ← NEW
tests/Feature/EventSerializationTest.php       ← NEW
tests/Feature/FakeSmsGatewayTest.php           ← NEW
tests/Feature/TextSmsPollingDlrReconcilerTest.php ← NEW
tests/Feature/ServiceProviderTest.php          ← NEW
tests/Feature/LogOnlySmsGatewayTest.php        ← NEW
tests/Feature/ArtisanCommandsTest.php          ← NEW
tests/Unit/ChainedNormalizerTest.php           ← NEW
tests/Unit/TextSmsRetryPolicyTest.php          ← NEW
```

## Out of scope (deferred, not v1.0.0 blockers)

- HMAC-signed webhooks for future provider integrations (still
  deferred per `docs/roadmap.md`).
- Multi-sender-ID rotation pools (v1.1).
- Multi-provider failover (`FailoverSmsSender`) — documented extension
  recipe stays in `docs/extending-providers.md`.
- Provider-side `timeToSend` scheduling.
- Lowest-dependency advisory triage policy — the advisories surfaced
  during review are in old transitive dev dependencies; the
  compatibility test passes. Triage in a future PR.

## Risk register

| Risk | Mitigation |
|---|---|
| The polling reconciler (B.2) requires one authorised live API call to capture the `/getdlr/` response fixture | Use the same `07XXXXXXXX` test recipient already approved for QPIMS-side testing. |
| The renamed migration file (C.1) breaks Orchestra Testbench bootstrap | TestCase refactor in the same PR; verified by re-running the suite as part of PR 7. |
| `SerializesModels` (C.4) could break the existing event-fake tests that compare model instances by identity | Re-run the 17 existing gateway tests on every event-class change; the existing assertions use `provider_message_id` (a column) rather than instance identity, so this should pass. |
| The retry-policy refactor (A.4) silently changes timeout behaviour for consumers depending on the current `retry(2, 200)` | The new `TextSmsRetryPolicy` defaults to the same 2-attempt cap with the same 200ms base delay for the supported retry kinds (transport). Documented as a `BC BREAK:` line in CHANGELOG nonetheless. |
| The `FakeSmsGateway::install()` throwing change (C.6) could break existing consumer tests that relied on the silent no-op | Documented in CHANGELOG as a `BC BREAK:` with migration steps. Likelihood is low — consumers calling `install()` outside an app context were silently testing nothing. |
