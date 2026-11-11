# T-14 — Test-double fidelity

**Date:** 2026-11-11, Africa/Nairobi. **Status:** implemented; scoped gates
and root-coordinated independent review accepted; root full integration gate pending.

## Scope and plan

Change only `FakeSmsGateway`, `LogOnlySmsGateway` and their new feature-test
files. Preserve the native morph association already implemented by T-13.
Add regressions first, run a proper red gate, then fix existing boundaries:
application-aware installation/cache invalidation; normalized validated input;
complete bulk preflight and configured-connection transactions; honest storage
failures; failed fake rows/events before the configured exception; contained
immediate listener/reporter/logging failures. Keep fake failures sticky and
document that policy. Callback methods remain passive no-ops.

No new abstraction, optional persistence mode, delivery simulation, schema
change, provider request or commit belongs to this packet. Root owns migration,
provider, README/CHANGELOG and integrated-gate changes concurrently.

## Implementation and mental model

Both doubles now prepare the complete batch, save all outcomes inside one
transaction on the dispatch model's configured connection, then publish in
input order. Single sends use the same preparation/persistence boundary.
They normalize recipients, trim bodies, validate bulk item types, enforce
the 20-message ceiling, generate client ULIDs, reject blank/oversized or
duplicate references and JSON-check outbound fields/metadata before writes.
Native morph association from T-13 remains in both writers.

The fake requires an application to install. Installation replaces both
contracts and calls `forgetDrivers()` only if the notification manager was
already resolved. It no longer pretends that failed database persistence
produced a saved row. Thrown or cancelled saves abort the transaction and
publish no success event. Only successfully persisted accepted sends enter
the fake's `sent` assertions.

`shouldThrow()` remains sticky, matching its existing public flag. Every
valid simulated failure persists failed rows with failure timestamps/code/
description, then fires failed events and throws the exact configured
`SmsProviderException`. Invalid input fails first. Failed simulations do not
enter the accepted-send assertions. No actual provider refusal is inferred
from a simulation, and no provider is contacted.

Immediate listener failures and failures of exception reporting are contained
with nested native `rescue()` calls. LogOnly writes its diagnostic log only
after every row is saved and contains logger failures. Callback methods still
ignore reports without state changes or delivery events; LogOnly's existing
sanitized ignored-payload diagnostic is retained with best-effort logging.

## Actual verification, November 11

| Gate | Actual result |
|---|---|
| Proper pre-fix focused red | 40 failed, 3 passed; 67 assertions; seed 1794379842 |
| Focused double green | 43 passed; 209 assertions; seed 1794380033 |
| Doubles + morph association + notification channel + webhooks | 95 passed; 446 assertions; seed 1794380169 |
| PHPStan on the two owned source classes | No errors |
| Pint on the four owned PHP files | Formatting applied to the fake and both new test files; final `--test` passed |
| Whitespace on owned tracked paths | `git diff --check` passed |
| Independent adversarial review | `nov05_gateway_crosscheck` accepted; separate Testbench probes confirmed cached-driver replacement, sticky failed-batch exception preservation despite broken listener/reporter, and LogOnly batch success despite logger/listener/reporter failures, with zero HTTP requests |

The initial red run exposed one test-harness cleanup issue: a fresh application
changed the global container, which the test now restores along with the
facade application. A subsequent run overlapped the coordinator's migration
rename and failed bootstrap (43 failed, zero assertions); it is not defect
evidence. The proper red above loaded the native migration. The first
implementation pass incorrectly referenced the real gateway's private bulk
constant; local private constants corrected that without expanding ownership.
The pre-existing Imagick version warning appears at PHP startup.

## Invariants, failure and remaining limits

- Invalid later items create no rows/events; a later storage error or cancelled
  save rolls the entire simulated batch back on the configured connection.
- All rows save before SDK publication, with accepted/failure results and
  input ordering preserved despite immediate consumer/reporting failures.
- The fake has no optional in-memory persistence fallback. Storage exceptions
  propagate and remain diagnosable by the consumer test.
- Tests use synthetic recipients/data, prevented stray HTTP requests and SQLite,
  including alternate configured connections. They do not establish MySQL,
  PostgreSQL, provider behavior, durable publication or exactly-once sending.
- Transactions can join a host's outer transaction. Immediate publication is
  still best effort; the first throwing listener can stop later listeners on
  the same event. Deferred queued/after-commit work follows host handling.

Changed paths: the two source classes, `tests/Feature/FakeSmsGatewayTest.php`,
`tests/Feature/LogOnlySmsGatewayTest.php` and this record only. Existing tests,
README/CHANGELOG, provider/migration boundaries and other task records were not
edited by this packet. No commit, dependency update or provider request occurred.
