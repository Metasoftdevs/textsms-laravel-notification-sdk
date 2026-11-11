# Implementation Review & Production-Readiness Enhancement Plan

**Author:** Claude (independent review pass)
**Date:** 2026-07-05
**Reviewed commit:** `f6b7627` (branch `main`, working tree clean apart from untracked `.docs/`)
**Prior art reviewed:** `.docs/dev/reviews/2026-06-09-package-readiness-review.md`,
`.docs/dev/reviews/Plan Address 2026-06-09 Package Readiness Review - ….md`,
`.docs/dev/reviews/packagist-publication-checklist.md`,
`docs/package-development-plan.md`, `docs/roadmap.md`, and every file under `src/`,
`config/`, `routes/`, `database/`, `tests/`, `.github/`.

---

## 0. Executive summary

The package is architecturally sound: contracts are narrow and correctly split,
the TextSMS adapter isolates every provider quirk, domain reconciliation is
event-driven, and the quality gates (Pest, PHPStan level 8, Pint, composer
validate) are all green **as re-verified today**. It is *not* yet safe to tag
`v1.0.0`: three state-integrity defects in `TextSmsGateway` and an
over-aggressive HTTP retry were **empirically re-confirmed today** with a
temporary probe suite (§2). All are fixable with small, Laravel-idiomatic
changes — no new abstractions are required for any release blocker.

The 2026-06-09 review is substantially accurate. This document independently
re-verifies its claims, corrects the emphasis in two places (retry-policy
design and the polling-reconciler design constraint), and lays out a
consolidated execution plan that deliberately **avoids the added complexity**
in the earlier remediation plan where Laravel-native mechanisms already do the
job (§4.4, §5).

**Verdict:** hold `v1.0.0` until Phase 1 + Phase 2 + Phase 3 of §6 are done.
Phase 4 (coverage/CI/hygiene) gates the stable tag, not the RC.

---

## 1. Baseline verification (run 2026-07-05, this machine)

| Check | Command | Result |
|---|---|---|
| Test suite | `vendor/bin/pest` | **52 passed, 131 assertions** (4.27s) |
| Static analysis | `vendor/bin/phpstan analyse` | **Level 8, no errors** |
| Code style | `vendor/bin/pint --test` | **Passed** |
| Composer manifest | `composer validate --strict` | **Valid** |
| `publishesMigrations()` availability | grep of installed `Illuminate\Support\ServiceProvider` | **Present (line 326)** — the recommended migration-publishing fix is viable on the current dependency set |
| `afterSending()` hook existence | grep of installed `Illuminate\Notifications\NotificationSender` | **Present (lines 178–179)** — the `SmsChannel` docblock claim about return-value forwarding is accurate |
| `.phpunit.cache` hygiene | `git ls-files` | Not tracked; correctly ignored |

Environment note: local PHP is 8.2.12 on Windows; CI runs 8.2/8.3 on Ubuntu.

---

## 2. Empirical re-verification of the claimed defects

A temporary probe file (`tests/Feature/TempReviewProbeTest.php`, deleted after
the run — per this review's no-permanent-artifacts rule) asserted the *current*
buggy behaviour. **All six probes passed (16 assertions)**, i.e. every probed
finding is real on `f6b7627`:

| Probe | Finding | Observed behaviour (confirmed) |
|---|---|---|
| P1 | Review #1 (Critical) — listener corruption | A throwing `SmsDispatchAccepted` listener lands in `send()`'s `catch (Throwable)`: the row keeps `provider_message_id=424242` and `sent_at`, yet ends `status=failed` with `failed_at` set, and the caller receives a spurious `SmsProviderException`. Provider truth is rewritten by consumer code. |
| P2 | Review #3 (High) — non-atomic bulk | `sendBulk([valid, invalid-recipient])` throws `SmsDispatchException`, sends **zero** HTTP requests, and strands **one permanent `pending` row**. |
| P3 | Review #3b — malformed item shape | `sendBulk([['recipient' => '0712…']])` (no `message` key) raises a raw PHP error, **not** the `SmsDispatchException` promised by the `SmsSender` contract docblock. |
| P4 | Review #4 (High) — DLR not idempotent | `Failed` DLR then `Delivered` DLR on the same row → `status=delivered` with **both** `delivered_at` and `failed_at` populated. Contradictory terminal state confirmed. |
| P5 | Review #11 (Medium) — balance misclassification | `getbalance` returning `{"response-code":1006,…}` at HTTP 200 is returned verbatim as "balance data"; no exception. `textsms:balance` would exit 0 and print an error payload as a balance table. |
| P6 | Review #5 (High) — blind retry, **new detail** | With the hard-coded `->retry(2, 200, throw: false)`, a **permanent HTTP 422 validation rejection is sent twice** (`Http::assertSentCount(2)` passes). Laravel's client retries on any `RequestException` (i.e. any non-2xx), not just transport failures. Consequences: (a) permanent errors double provider traffic and delay failure surfacing by the backoff; (b) on an ambiguous timeout after the provider accepted the message, the POST is re-sent → **duplicate SMS + double billing risk**, since TextSMS documents no `clientsmsid` idempotency. |

Additional contract check performed against the bundled provider docs
(`docs/text-sms-gateway-api-docs/bulksmsUpdated.postman (1).txt`):

- The **only documented DLR mechanism is pull**: `POST|GET /api/services/getdlr/`
  taking `apikey`, `partnerID`, and **a single `messageID` per call** (lines
  343–420 of the collection). No push-callback method, payload schema, retry
  semantics, or expected response is documented anywhere in the bundle. This
  confirms Review #2 — and adds a design constraint the earlier remediation
  plan missed: **a polling reconciler must issue one HTTP call per awaiting
  row** (there is no batch DLR query), so its batch size is an HTTP-call budget,
  not a paging size (§6, Phase 2).

---

## 3. Independent findings not in (or refining) the 2026-06-09 review

These came out of this review's own read of the code. None is a release
blocker on its own; items N1 and N2 fold into existing blocker work, the rest
are Phase 3/4 material.

### N1. The retry fix does not need a new abstraction *(refines review #5 / plan A.4)*

The earlier remediation plan proposes a `RetryPolicy` contract plus two
implementations and a manual retry loop. That contradicts the stated goal of
not adding complexity, and Laravel's HTTP client already supports exactly the
needed semantics natively:

```php
// TextSmsGateway::http() — the whole fix
return Http::baseUrl($this->baseUrl)
    ->asJson()
    ->acceptJson()
    ->timeout($this->timeoutSeconds)
    ->retry(
        times: $this->retryTimes,          // config: textsms.providers.textsms.retry.times
        sleepMilliseconds: $this->retryDelayMs,
        when: fn (Throwable $e): bool => $e instanceof ConnectionException,
        throw: false,
    );
```

`when:` restricted to `ConnectionException` means: never retry a received
response (422/5xx/provider codes are terminal per attempt), retry only when no
response arrived. The residual ambiguous-timeout duplicate risk cannot be
eliminated client-side without provider idempotency support, so it is
**documented** and made **configurable** (`retry.times = 0` disables). The
`RetryPolicy` interface stays on the roadmap for when multi-provider failover
lands (its first real consumer). This achieves the same test matrix the old
plan required ("no retry on 1003/1004/1006", "retries on ConnectionException")
with ~10 changed lines and two config keys.

### N2. Polling reconciler is per-message, so cost must be bounded *(refines plan B.2)*

Confirmed from the Postman collection (§2). Design consequences adopted in
Phase 2: `scopeAwaitingDlr()` (already on the model) drives candidate
selection; `dlr_polling.batch_size` caps **HTTP calls per run**; a
`lookback_minutes` floor and a `give_up_after_minutes` ceiling bound the
candidate window so rows the provider will never report on don't get polled
forever. The command also accepts `--message-id=` for ad-hoc single-row
checks during ops work.

### N3. `status` column is a DB `enum` — hostile to evolution

`database/migrations/…create_sms_dispatches_table.php.stub:42` uses
`$table->enum('status', [...])`. Adding a state later (e.g. an actually-used
`rejected`, or `expired`) requires an `ALTER TABLE … MODIFY` on MySQL, which is
painful on large tables. The model docblock already documents a `rejected`
state **that no code path ever sets** — the enum and the code disagree today.
Since the package is pre-1.0 with no external consumers, the cheap fix is now:
`$table->string('status', 16)->default('pending')->index()` and keep statuses
as plain strings (matching current code). A PHP backed enum is optional
API surface and deliberately deferred.

### N4. `nullableMorphs('notifiable')` locks notifiable keys to integers

`notifiable_id` is `unsignedBigInteger`; a consumer whose `User` uses ULID/UUID
keys gets a DB error on insert (`$notifiable->getKey()` returns a string). The
publication checklist already asks to "test and document behaviour with
UUID/ULID notifiable models" but the schema makes the answer "it breaks".
Prudent v1.0 fix: document the limitation in README + migration docblock and
note that consumers with UUID keys should edit the **published** migration to
`nullableUuidMorphs`/`nullableUlidMorphs`. Making it config-driven is deferred.

### N5. Channel registration eagerly instantiates `ChannelManager`

`TextSmsNotificationServiceProvider::registerChannel()` calls
`$this->app->make(ChannelManager::class)` during `boot()` of every request.
The Laravel-idiomatic form avoids constructing the manager until something
actually resolves it:

```php
$this->callAfterResolving(ChannelManager::class, function (ChannelManager $manager): void {
    $manager->extend('sms', fn ($app) => new SmsChannel(
        sender: $app->make(SmsSender::class),
        logger: $app->make(LoggerInterface::class),
    ));
});
```

Zero behaviour change for consumers, one fewer eager service per request.
Also removes the dead `HttpKernel` no-op block in `registerMiddlewareAlias()`
(lines 158–161) — it does nothing and its comment admits it.

### N6. Credentials fail late and vaguely

With `TEXTSMS_API_KEY` unset, the gateway is constructed with `''` and the
failure surfaces as a provider `1006` after a real HTTP round-trip. A guard at
the top of `send()`/`sendBulk()`/`balance()` throwing
`SmsProviderException('TextSMS credentials are not configured …')` before any
HTTP call gives operators an actionable message and saves a wasted request.
Small, no new surface.

### N7. `LogOnlySmsGateway` / `FakeSmsGateway` parity gaps

- `LogOnlySmsGateway::send()` leaves `client_message_id` null when the message
  carries no `clientReference` (the real gateway generates a ULID). Trivial
  parity fix.
- Neither `LogOnlySmsGateway::sendBulk()` nor `FakeSmsGateway::sendBulk()`
  enforces the 20-item limit, so tests written against the fakes cannot catch
  over-limit bugs that the real gateway throws on. Add the same limit check
  (constant already public on `TextSmsGateway`; hoist `BULK_LIMIT` to the
  `SmsSender` interface docs or a shared constant — simplest: duplicate the
  check with a named local constant referencing the provider limit).

### N8. Test-suite blind spots beyond the earlier review's list

- No test covers the `dlrstatus` alternate DLR key (`TextSmsGateway.php:316`
  reads `status` ?? `dlrstatus`; only `status` is exercised).
- No test covers `messageID` (capital ID) alternate key in the webhook
  controller path end-to-end.
- No test pins the **HTTP attempt count** on failure shapes (P6 shows why this
  matters — the retry semantics were invisible to the suite).

### N9. CI / toolchain currency

- Matrix is PHP 8.2/8.3 only. Laravel 12 supports PHP 8.4 — add it. (PHP 8.5:
  verify library ecosystem support at execution time before adding.)
- No `prefer-lowest` job, no `composer validate --strict`, no `composer audit`
  in CI (already noted as D.7/D.8 in the earlier plan; kept here).
- `composer.lock` is git-ignored (correct for a library), so CI must run
  `composer audit` **after install**, not `composer audit --locked` as the
  publication checklist currently says. Correct the checklist.
- Laravel 13: given the framework's yearly cadence it may exist by now.
  **Verify at execution time**; if released, add a `13.*` matrix row +
  `orchestra/testbench ^11` and widen constraints only after the suite is
  green on it. Do not widen speculatively.

### N10. Minor doc/config truthfulness

- `config/textsms.php` header says "five credential keys" but two of the five
  are optional — trivial wording fix while touching the file.
- `docs/roadmap.md` "v1.0 — shipped" already claims 52 tests green as shipped;
  after this plan the counts change — update alongside.
- `.gitattributes` excludes non-existent `.php-cs-fixer.dist.php` and ships
  `pint.json` in the dist (earlier finding, kept: remove stale line, add
  `/pint.json export-ignore`).

### Claims from the earlier review checked and confirmed accurate (no change)

- Token leak via `logDenial()` logging `fullUrl()` (middleware line 87) — real;
  also triggered on the unconfigured-secret path, so one redaction helper
  covers both.
- Controller's promised-but-absent 422 logging — real (no logger injected).
- Events lack `SerializesModels` — real. One nuance added: after adding it,
  also **document** that consumers dispatching SMS inside DB transactions
  should mark queued listeners `afterCommit` (Laravel-standard guidance);
  do not add `ShouldDispatchAfterCommit` to the events — it would silently
  change sync-listener timing for all consumers.
- `FakeSmsGateway` honesty issues (silent no-app install, swallow-all
  persistence catch, dead `recordDeliveryReport`) — all real as written.
- Service provider publishes the migration with a boot-time `date()` timestamp
  instead of `publishesMigrations()` — real; fix verified available (§1).

---

## 4. Assessment of the existing remediation plan (what to keep, what to change)

| Stream (existing plan) | Verdict | Adjustment |
|---|---|---|
| A.1 listener isolation | **Keep** | Simplify: no `classifyAndPersist()` tuple indirection needed. Move the two `::dispatch()` calls out of the `try`; wrap `SmsDispatchFailed::dispatch()` in its own guarded try/catch that logs and continues (the provider exception must win). |
| A.2 atomic bulk preflight | **Keep as designed** (two-pass validate-then-insert) | Also type-check item shape explicitly so the contract's `SmsDispatchException` promise holds (P3). |
| A.3 idempotent DLR policy | **Keep as designed** (transition table) | Table adopted verbatim in Phase 1.3 below. |
| A.4 RetryPolicy contract + 2 classes + manual loop | **Replace** | Laravel-native `retry(when: ConnectionException)` + 2 config keys (§3-N1). Interface deferred to roadmap. |
| B.1 reframe push-DLR docs | **Keep** | Wording as drafted. |
| B.2 polling reconciler | **Keep, redesign constraint** | Per-message `/getdlr/` (§3-N2): batch_size = HTTP-call budget; add give-up ceiling; `--message-id` ad-hoc flag. Response-shape fixture still requires one authorised live call. |
| C.1–C.6 | **Keep all** | C.4 gains the after-commit documentation note (§3). |
| D.1–D.8 | **Keep all** | Fold in N8 test gaps and N9 CI currency. |
| 18 sequential PRs | **Consolidate** | Single-maintainer repo; 18 PRs is ceremony. Four phase-branches with the same regression-test discipline (§6). |

---

## 5. Guiding principles for execution (restating the mandate)

1. **Laravel-framework way first.** Every fix below uses a mechanism Laravel
   already ships: `Http::retry(when:)`, `publishesMigrations()`,
   `SerializesModels`, `callAfterResolving()`, scheduler-driven commands,
   config keys. No new contracts, no new packages, no new layers in v1.0.
2. **No fix without a probe-turned-regression-test.** Each Phase 1 defect
   already has a proven failing-behaviour probe (§2); the fix PR flips the
   probe's assertions into the desired behaviour and keeps it permanently.
3. **Pre-1.0 is the moment for schema/API corrections** (enum→string,
   fake-gateway BC changes). After 1.0 these become breaking changes; do them
   now and record them in `CHANGELOG.md`.
4. **Verify, don't infer.** Anything marked "verify at execution time"
   (Laravel 13, PHP 8.5, live `/getdlr/` response shape) gets an explicit
   verification step before code depends on it.

---

## 6. Execution plan

Every task lists: files, the change, and the verification artefact. All work
lands on short-lived branches off `main`; each phase ends with the full gate
(`pest` + `phpstan` + `pint --test` + `composer validate --strict`) green.

### Phase 1 — State integrity (release blockers; must precede any tag)

#### 1.1 Isolate lifecycle events from provider classification
- **Files:** `src/Gateways/TextSmsGateway.php` (`send()`, `sendBulk()`,
  `recordDeliveryReport()`).
- **Change:** `SmsDispatchAccepted::dispatch()` moves after the `try/catch`
  (fire only when classification concluded "accepted"). `SmsDispatchFailed::dispatch()`
  calls get wrapped in a private `dispatchFailedEventSafely(...)` that catches
  `Throwable` from listeners, logs `warning('listener for SmsDispatchFailed threw', …)`,
  and never masks the in-flight `SmsProviderException`. In
  `recordDeliveryReport()`, `SmsDeliveryReported::dispatch()` already runs
  after persistence — add the same guarded dispatch so a throwing listener
  cannot bubble a 500 out of the webhook controller after state was correctly
  persisted (current behaviour: it can).
- **Tests (regression, from P1):**
  - throwing accepted-listener → row stays `sent`, `failed_at` null, original
    listener exception propagates to the caller **as itself** (a listener error
    is the consumer's bug — it must not be disguised as `SmsProviderException`).
    Decision point recorded: the channel's catch list will *not* swallow it,
    which is correct — a broken consumer listener should fail loudly.
  - throwing failed-listener → original `SmsProviderException` still thrown,
    listener error logged.
  - throwing `SmsDeliveryReported` listener → DLR row state persisted, webhook
    still returns 200, listener error logged.

#### 1.2 Atomic `sendBulk()` preflight
- **Files:** `src/Gateways/TextSmsGateway.php`.
- **Change:** two-pass. Pass 1 `prepareBulkItems(array $items): array` —
  validates shape (`recipient` string, `message` instanceof `SmsMessage`,
  `notifiable` null|Model), normalises recipients, trims bodies, resolves
  sender/client ids; throws `SmsDispatchException` naming the offending index;
  **no writes**. Pass 2 pre-inserts all rows and POSTs. Wrap pass-2 inserts in
  `DB::transaction()` on the model's connection so a mid-loop insert failure
  leaves zero rows.
- **Tests (from P2/P3):** invalid later recipient → zero rows, zero HTTP;
  missing `message` key → `SmsDispatchException` mentioning index 0; wrong
  message type → `SmsDispatchException`.

#### 1.3 Idempotent DLR state transitions
- **Files:** `src/Gateways/TextSmsGateway.php` (`recordDeliveryReport()`).
- **Change:** apply the transition policy (adopted from the earlier plan):

  | Current → incoming | status | delivered_at | failed_at |
  |---|---|---|---|
  | any → delivered | `delivered` | set if null (preserve first) | **cleared** |
  | any → failed | `failed` | **cleared** | set if null (preserve first) |
  | same terminal repeat | unchanged | preserved | preserved |
  | any → unknown | unchanged | unchanged | unchanged |

  `dlr_payload` is always replaced with the latest payload (documented:
  last-write-wins; full history belongs in consumer listeners if needed).
- **Tests (from P4):** reversal both directions clears the opposite timestamp;
  repeats preserve first timestamps; unknown persists payload only.

#### 1.4 Scope HTTP retries to transport failures (Laravel-native)
- **Files:** `src/Gateways/TextSmsGateway.php` (`http()`, constructor),
  `src/TextSmsNotificationServiceProvider.php` (pass config),
  `config/textsms.php` (new `providers.textsms.retry` block:
  `['times' => 2, 'delay_ms' => 200]`; `times: 0/1` disables retries).
- **Change:** as §3-N1. Document the ambiguous-timeout duplicate risk in the
  config block and `docs/operations.md`.
- **Tests (from P6):** 422 validation rejection → exactly 1 HTTP attempt;
  provider-code failures at HTTP 200 → 1 attempt; `ConnectionException` →
  retried up to `times`; `retry.times = 0` → single attempt even on
  `ConnectionException`.

**Phase 1 exit gate:** full suite green (expect ~65+ tests), PHPStan 8, Pint;
CHANGELOG `[Unreleased]` entries under `### Fixed`.

### Phase 2 — Delivery-report contract honesty (release blocker)

#### 2.1 Reframe push-DLR as observed-not-guaranteed
- **Files:** `README.md`, `docs/webhooks.md`, `docs/operations.md`,
  `config/textsms.php` webhook block comment,
  `TextSmsCallbackController` docblock.
- **Change:** wording per the earlier plan's B.1 draft (two supported paths:
  push webhook = empirically observed; pull poller = provider-documented).

#### 2.2 Ship the polling reconciler (provider-documented path)
- **New files:** `src/Dlr/TextSmsDlrPoller.php`,
  `src/Console/Commands/PollDlrCommand.php` (`textsms:dlr:poll {--message-id=}`).
- **Design (respecting the per-message constraint, §3-N2):**
  - Candidates: `SmsDispatch::awaitingDlr()` scoped to `provider='textsms'`,
    `sent_at` between `now()-give_up_after_minutes` and `now()-lookback floor`,
    ordered oldest-first, limited to `batch_size` (default 50 = 50 HTTP calls).
  - One `POST /api/services/getdlr/` per row (`apikey`, `partnerID`,
    `messageID`); response classified through the **same**
    `classifyDeliveryReportStatus()` + Phase 1.3 transition policy, firing
    `SmsDeliveryReported` identically to the webhook path (single code path —
    extract the shared persistence+event logic into a private method both
    entry points call).
  - Config: `textsms.dlr_polling = ['batch_size' => 50, 'lookback_minutes' => 5, 'give_up_after_minutes' => 2880]`.
  - Consumers schedule it themselves (documented):
    `Schedule::command('textsms:dlr:poll')->everyFiveMinutes()->withoutOverlapping()`.
- **Fixture requirement (verify at execution time):** the `/getdlr/` *response*
  shape is undocumented in the bundle. One authorised live call against the
  approved test recipient (`07XXXXXXXX`, per the QPIMS-era approval) captures a
  sanitised fixture committed under `tests/Fixtures/`. Until that call is made,
  the poller's response parsing follows the webhook-payload keys
  (`messageid`/`status`) defensively and treats unrecognised shapes as
  `unknown` (no state flips) — fail-safe by construction.
- **Tests:** poller respects window + batch size; flips delivered/failed rows
  through the shared policy; fires the event; unknown/unparseable responses
  leave rows untouched; `--message-id` polls exactly one row.

**Phase 2 exit gate:** suite green; docs updated; fixture either committed or
explicitly marked pending-live-call in CHANGELOG.

### Phase 3 — Package mechanics & hardening (before RC tag)

| # | Task | Files | Verification |
|---|---|---|---|
| 3.1 | `publishesMigrations()`; rename stub → `.php`; TestCase uses `loadMigrationsFrom()` | service provider, `database/migrations/`, `tests/TestCase.php` | New `MigrationPublishingTest`: republish is repeat-safe; suite still boots |
| 3.2 | Redact `token` in denial logs (`sanitisedUrl()` helper) | `VerifyTextSmsCallback` | Webhook test asserts `[REDACTED]` via `Log::spy()` |
| 3.3 | Log malformed-payload 422s (inject `LoggerInterface`) | `TextSmsCallbackController` | Test asserts warning emitted |
| 3.4 | `SerializesModels` on all three events + after-commit guidance in `docs/events.md` | 3 event classes | `EventSerializationTest`: queue round-trip re-fetches model |
| 3.5 | Classify `balance()` failures via existing top-level parser (throw on `response-code` present or unparseable) | `TextSmsGateway::balance()` | Regression from P5: 1006-at-200 throws; HTML 5xx throws |
| 3.6 | `FakeSmsGateway` honesty: `install()` throws without app; remove swallow-all catch (opt-in `withoutPersistence()`); add `simulateDelivery()`/`simulateFailure()` firing `SmsDeliveryReported` | `src/Testing/FakeSmsGateway.php` | New `FakeSmsGatewayTest` (6 cases per earlier plan C.6) — BC note in CHANGELOG |
| 3.7 | Credential guard: throw configured-credentials-missing `SmsProviderException` before any HTTP when `api_key`/`partner_id` empty (§3-N6) | `TextSmsGateway` | Test: empty key → no HTTP call, clear message |
| 3.8 | `callAfterResolving(ChannelManager::class, …)`; delete dead `HttpKernel` block (§3-N5) | service provider | Existing channel tests still pass; new SP test asserts `sms` driver resolves |
| 3.9 | Migration: `enum`→`string(16)` status (§3-N3); document morph-key limitation (§3-N4); align `attempt_count` default to 1 or document semantics | migration, `SmsDispatch` docblock, README | Suite green on fresh migration; docblock no longer advertises never-set `rejected` unless kept deliberately (decide: keep `rejected` documented as reserved) |
| 3.10 | `LogOnlySmsGateway` parity: generate client id; enforce bulk limit in both fakes (§3-N7) | `LogOnlySmsGateway`, `FakeSmsGateway` | New `LogOnlySmsGatewayTest` |

**Phase 3 exit gate:** full gate green. Tag **`v1.0.0-rc1`** (annotated), after
repo is public and CI green — per the publication checklist §9.

### Phase 4 — Coverage, CI, hygiene (before stable `v1.0.0`)

1. **New test files:** `ServiceProviderTest` (bindings, publish groups,
   middleware alias, conditional route loading, mismatched-sender exception),
   `ArtisanCommandsTest` (all 4 commands incl. `textsms:dlr:poll`),
   `ChainedNormalizerTest`, plus N8 gaps (`dlrstatus` key, `messageID` casing,
   attempt-count pinning is covered by 1.4).
2. **CI:** add PHP 8.4 to matrix; add `stability: prefer-lowest` row; add
   `composer validate --strict` + `composer audit` (post-install, not
   `--locked`) jobs; verify-then-maybe-add Laravel 13 row (§3-N9).
3. **Hygiene:** `.gitattributes` (drop `.php-cs-fixer.dist.php` line, add
   `/pint.json export-ignore`); config comment wording (§3-N10); per-method
   PHPDoc pass on public surfaces (earlier plan D.5).
4. **Docs sync:** README (retry + polling + morph-key note), `docs/roadmap.md`
   (strike polling reconciler from deferred; RetryPolicy stays deferred with a
   pointer to the Laravel-native mechanism now in place), `docs/operations.md`
   (poll scheduling, duplicate-risk note), CHANGELOG release section.
5. **Release:** follow `.docs/dev/reviews/packagist-publication-checklist.md`
   §§7–12 verbatim (fresh-install smoke, live send smoke, DLR confirmation via
   poller and/or webhook, tag, Packagist submit) with the one correction from
   §3-N9 (`composer audit` form).

**Target end-state:** ~95–105 tests, PHPStan 8, Pint, validate, audit — all
green on the exact tagged commit.

---

## 7. Explicitly out of scope for v1.0 (unchanged deferrals, complexity guard)

- `RetryPolicy` contract / retry classes — superseded by Laravel-native
  `retry(when:)` (§3-N1); revisit with failover in v1.x.
- Multi-sender-ID pools, `FailoverSmsSender`, HMAC middleware,
  `QueuedSmsSender`, `timeToSend` scheduling, non-Kenyan normalisers,
  `textsms:dispatch:retry-failed` — all remain documented recipes per
  `docs/roadmap.md`.
- PHP backed enum for dispatch status; config-driven morph key type — noted in
  §3-N3/N4, deliberately deferred.

## 8. Risk register (delta from the earlier plan)

| Risk | Mitigation |
|---|---|
| Listener-exception propagation choice (1.1) surprises consumers who relied on the channel swallowing everything | The channel still swallows `SmsDispatchException`/`SmsProviderException`; only *consumer listener* errors now propagate on the accepted path. CHANGELOG `BC` note + docs/events.md guidance (queue listeners; use `afterCommit`). |
| `/getdlr/` live response shape differs from webhook keys | Poller treats unknown shapes as `unknown` (no state flip) — fail-safe until the fixture call is made. |
| Enum→string migration change breaks anyone already on `e1db5df` | Pre-1.0, unpublished on Packagist, repo private as of 2026-06-09 review — no external consumers exist. Recorded in CHANGELOG regardless. |
| `FakeSmsGateway` BC changes (3.6) | Same rationale; documented migration note. |
| Laravel 13 / PHP 8.5 support widened without proof | Gated behind execution-time verification (§3-N9); constraints widen only on a green matrix run. |

## 9. Verification protocol for the implementation phase

Per the working agreement for this repo: every Phase 1–3 behavioural change is
first reproduced by a temporary probe (as done in §2), the fix is implemented,
the probe's assertions are inverted into the permanent regression test, and
the temporary file is deleted. Full gate (`composer test`, `composer phpstan`,
`composer pint-test`, `composer validate --strict`) runs at each phase exit,
and each phase's changes are documented in `.docs/dev/` alongside this file
before merging.
