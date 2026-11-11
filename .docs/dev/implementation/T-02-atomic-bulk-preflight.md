# T-02 - Atomic Bulk Preparation and Response Correlation

**Date:** 2026-11-02, Africa/Nairobi. **Status:** accepted after independent code corrections, documentation re-review and the integrated gate (17:04 EAT). **Owner:** Codex. **References:** November T-02/section 6.4, adversarial AR-02, and T-01 normal-exception carryovers.

## Planned changes and engineering contract

1. Validate and normalize every bulk item before writes: array shape, string recipient, `SmsMessage`, trimmed nonempty body, null/model notifiable, usable unique resolved client reference. Invalid input raises indexed `SmsDispatchException`; no rows or requests.
2. Insert the prepared rows in one transaction on the `SmsDispatch` model's configured connection. Failure before commit rolls the batch back and makes no provider call. A host callback that throws after commit can leave the complete pending batch, still without an HTTP request; there is no compensating deletion. The provider POST occurs after that transaction scope, never inside a database transaction retry callback. An enclosing consumer transaction remains an external durability limitation.
3. Validate provider response shape/types before string conversion or correlation. Preserve exact client-reference matching and input-order results. Duplicate/missing/unexpected/malformed response entries must not silently overwrite mappings or trigger positional guessing. Preserve independently valid sibling results.
4. Response code 200 needs a usable provider message ID and a successful HTTP status to count as accepted. Incomplete or contradictory acceptance becomes a local failed attempt with explicit unknown-acceptance diagnostics; it is not evidence of provider rejection or safe resend. Do not change the persisted state vocabulary or claim globally unique provider IDs.
5. Unknown/missing correlation uses `provider_diagnostics.parse_source = unmatched`; other anomalies use specific diagnostic sources and `provider_acceptance = unknown`. A bounded best-effort warning summarizes each anomalous batch without request bodies, recipients, credentials, or raw responses.
6. Align ordinary single/global exceptions with T-01 certainty: unknown output says the provider may have accepted it and automatic resend is unsafe; explicit refusals retain code/description/errors. Bulk per-item failures remain returned rows; whole-response/global failure still throws the existing package exception.
7. Keep T-01's persist-then-publish and diagnostic isolation intact. No retry, DLR precedence, morph, doubles, provider-poller, or new public abstraction belongs here.

## Owned paths

`src/Gateways/TextSmsGateway.php`, relevant contract PHPDoc, `tests/Feature/TextSmsGatewayTest.php` and a dedicated `tests/Feature/BulkPreparationAndCorrelationTest.php`, targeted bulk/operations/event documentation and CHANGELOG, this record, and the execution-status record. Shared PHP files have one writer.

## Verification plan

Write regression cases before implementation for malformed/invalid later items and duplicate references; empty/20/21 boundaries; second-insert rollback on default and alternate model connections; reordered/missing/duplicate/unexpected/malformed response entries; duplicate provider IDs within a batch; success without a usable ID; conflicting HTTP/body success; truthful unknown exceptions and retained known refusal. Assert row state, events, diagnostic certainty, warning content/count, input order, and provider request counts.

Then run focused tests, PHPStan, formatter/style checks, full suite, Composer validation, whitespace checks, and adversarial review of the resulting boundary. Only actual outputs and dates will be recorded as evidence. T-07 remains necessary to prove live client-reference echo and provider schemas.

## Implemented behavior and adversarial corrections

The actual flow is complete preflight -> insertion transaction on the model connection -> one provider operation -> response validation and classification -> T-01 recording attempts for every row -> events for saved rows. Empty input returns an empty collection. Invalid later items, duplicate references and invalid UTF-8 leave zero rows and send nothing. Sparse input keys do not change output order. Metadata serialization and model-save failures during insertion roll back the insertion transaction.

Response mappings are built without overwriting duplicate keys. Usable provider-ID claims are counted across **all raw entries before filtering**, so an unexpected or malformed row can invalidate a colliding success candidate without erasing independent valid siblings. Conflicting top-level refusals/errors and per-message success become unknown; a successful call envelope can contain mixed per-message results. Anomalies produce one best-effort warning containing fixed labels and counts only.

The first independent review identified two parser defects: claims from invalid/duplicate rows were initially discarded before ID collision checks; top-level errors could initially be ignored in favor of success rows. Runtime review also identified invalid UTF-8 passing the initial preflight. Permanent regressions reproduced these defects before the bounded fixes. Both reviewers subsequently found no remaining blocker in their corrected code scopes; neither claimed to run tests. Runtime reviewer also accepted the final public documentation after its edits. The final record incorporates the review's postcommit qualification and gate evidence.

## Actual verification, 2026-11-02

| Check | Result |
|---|---|
| Initial regressions against pre-T-02 implementation | 30 failed, 4 passed, 56 assertions; seed 1793627050 |
| New review regressions against first T-02 implementation | 15 failed, 35 passed, 234 assertions; seed 1793627913 |
| Corrected gateway + T-01 + T-02 focused run | 122 passed, 824 assertions; seed 1793627967 |
| Full Pest suite | 179 passed, 1001 assertions; seed 1793628152 |
| PHPStan level 8 | No errors |
| Pint on owned files, then full `--test` | Passed |
| `composer validate --strict` | Valid |
| `git diff --check` | Passed |

All HTTP interactions use synthetic fixtures. No provider calls, dependency updates, commits, deployment changes or release publication occurred. SQLite proves local transaction/correlation behavior; it does not establish provider idempotency, real MySQL/PostgreSQL behavior or process-crash durability. Postcommit callback behavior is a framework-source qualification, not a newly injected test. No new random-seed matrix is claimed beyond the recorded runs.

## Comprehension and recovery summary

**Intent and invariant:** reject a bad batch before side effects and avoid falsely assigning a provider outcome. A known refusal requires a documented refusal in a usable response; failed local state does not prove refusal. No response position guessing or automatic recovery resend is introduced.

**Failure and recovery:** input errors identify the item and send nothing. Insertion exceptions say no request was sent. After provider I/O, unknown acceptance may mean a billed send; reconcile rather than blindly replaying. Existing T-01 recording exceptions preserve accepted/unknown/refused certainty and the local cause. No new state vocabulary or global provider-ID uniqueness guarantee is added.

**Artifacts and reviewer focus:** `tests/Feature/BulkPreparationAndCorrelationTest.php`, `docs/bulk-batching.md`, `docs/operations.md`, `docs/events.md`, the sender contract and CHANGELOG describe the implemented boundary. Outer transactions, interruption and early delivery-report timing remain explicit limitations. T-03 handles report transitions; T-04 handles the existing send retry policy; T-07 establishes the live provider contract.

## November 5 supplementary correction

Cross-validation found that global fallback could label an associative
response collection containing success data as a confirmed refusal.
Present non-null scalar/non-list collections now become malformed/unknown
before global classification. Absent/null/empty collections retain their
existing global-refusal behavior. Normal result and failed-recording paths
are covered. Independent re-review accepted the fix; the fresh integrated
gate is **209 passed, 1151 assertions**, seed 1793870801, with static/style,
strict Composer and whitespace checks clean. The full red/green chronology
and evidence qualifications are in `2026-11-05-cross-validation.md`.
