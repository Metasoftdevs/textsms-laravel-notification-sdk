# T-09 - Event Serialization and Listener Contract

> November 9 amendment: the serialization contract remains valid, including
> the retained legacy class. Approved D3 removes new default delivery-event
> emission and the callback-driven reconciliation recipe. Current behavior
> is documented in [passive reports](T-03-passive-delivery-reports.md).

**Date:** 2026-11-02, Africa/Nairobi. **Status:** accepted after integrated gate and independent review, including connection/table qualification. **Owner:** Codex. **Dependencies:** accepted T-01/D1-A; AR-04 listener ordering constraints.

## Plan and boundary

Use Laravel's `SerializesModels` on the three existing events. Verify serialization round trips restore the current persisted row (including readonly promoted properties), preserve immutable event scalar/payload data, and fail clearly if the dispatch has been deleted. Cover the ordered synchronous-listener failure limit without changing T-01 dispatch isolation. No event outbox or per-listener dispatcher is introduced.

Update `docs/events.md` and event PHPDoc to explain queued listener publication, host after-commit usage, row retention and missing-model failures, current-model versus historical-event data, and reconciliation of the current domain attempt. A queued listener is not durable until its job is submitted; T-01's first throwing synchronous listener can prevent later submission. Listener-level `$deleteWhenMissingModels` does not control `CallQueuedListener` deserialization.

Add regression tests before the trait changes, then focused checks, PHPStan/Pint, the integrated suite, and independent read-only review. Treat queue-driver execution and live-provider behavior as separate evidence, not as established by a PHP serialization test.

## Implemented contract and evidence

All three events now use the framework trait. `EventSerializationTest.php` checks updated dispatch state and metadata, preserved event scalar/payload history, deleted-row failure for each event type, and an ordered first-listener exception that prevents a later listener without changing the persisted send. The public recipe locks its domain row, re-reads current dispatch state, and checks the application's tracked current-attempt ULID before reconciliation. It does not initiate a resend.

| Check, 2026-11-02 | Actual result |
|---|---|
| Proper regressions before traits | 6 failed, 1 passed, 16 assertions; seed 1793628338 |
| T-09 plus T-01 focused run | 62 passed, 436 assertions; seed 1793628424 |
| Full suite | 186 passed, 1027 assertions; seed 1793643863 |
| PHPStan level 8 | No errors |
| Pint owned files and full `--test` | Passed |
| Composer strict validation / diff whitespace | Passed |

An initial test-harness run incorrectly used a nonexistent model factory and failed six cases before reaching the behavior assertions. It was corrected to use the existing `create()` boundary; only the subsequent proper red run above establishes regression evidence. No queue worker, provider, external service, commit or dependency update was used.

## Review qualification and recovery

The reviewer cross-checked readonly hydration, after-commit propagation and `CallQueuedListener` missing-model handling against installed framework source. They identified one existing model limitation activated by serialization: `SmsDispatch` resolves connection/table from current package configuration, so producer and worker configuration must match. The public docs now state this invariant, advise draining/migrating jobs before changing it, and make per-tenant queue context the application's responsibility. No new multi-tenant restoration mechanism or model-connection behavior is claimed.

**Intent and invariants:** queue event model identity without freezing a stale dispatch snapshot; retain historical event fields. Current state and current domain attempt govern reconciliation. T-01 still protects the send result but cannot guarantee later listeners run after a prior one throws.

**Failure/recovery and limits:** deleted rows fail restoration, so retain rows through queue retries. Enqueue after commit where appropriate, start outbound work after the business commit, and use idempotent domain effects. Queue submission is best effort; serialization is not durable event publication. Connection/table drift and concurrent domain effects require application coordination. The tests prove PHP model serialization, not operation of a production queue driver.

**Artifacts/reviewer focus:** `src/Events/*`, `tests/Feature/EventSerializationTest.php`, `docs/events.md`, and CHANGELOG. Verify restored-current versus historical-data semantics and connection/table stability when integrating a worker deployment.

## November 5 cross-validation qualification

Independent framework-source review identified that after-commit listeners
and deferred queue submission run later, outside the gateway's immediate
dispatch rescue; their failures can escape from host transaction commit.
The event docs, gateway docblock and CHANGELOG now state this limit and
prohibit inferring a safe SMS resend from deferred consumer failure.
Queue-worker failures remain application/queue responsibilities. No runtime
behavior or D1 choice changed.

Worker identity requirements now include the resolved database/schema/table,
default database and named connection targets. Merely matching nullable
package connection options does not ensure model identity. The evidence is
installed framework control flow and model configuration, not a newly
executed after-commit/worker experiment. November 2 test results above are
retained; November 5 gates are in `2026-11-05-cross-validation.md`.
