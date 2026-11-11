# Cross-validation and sequential continuation

**Date:** 2026-11-05, Africa/Nairobi. **Status:** corrections and bounded coverage accepted; remaining decision/external gates explicit. **Owner:** Codex. Existing source changes remain uncommitted.

## Verified starting point

The pasted handoff describes the earlier 129-test T-01 correction. The actual checkout already contains accepted T-02, T-09, T-15 and T-20 work from November 2. A fresh baseline run passes **191 tests, 1039 assertions**, seed 1793870310. PHPStan level 8, Pint, Composer strict validation and diff whitespace checks also pass. These are November 5 results; older records keep their original evidence dates.

Three independent read-only agents checked gateway certainty/correlation, event/integration semantics and plan status. They did not edit files or call the provider. The gateway reviewer executed a 14-fixture parser-level reflection probe; this is separate from root's database-backed Pest suite.

## Corrections planned before more implementation

1. **T-02, malformed-container fallback.** A global code 1004 with an associative `responses` object containing a successful row is currently a confirmed rejection in bulk, because global fallback discards that malformed collection. Preserve absent/null/empty collection compatibility, but make present non-null scalar and non-list collections unknown before global-code classification. Add normal-path and local-recording-failure regressions first. This does not change the refusal whitelist or choose retry policy.
2. **T-09 documentation boundary.** Qualify isolation as exceptions raised during immediate gateway event dispatch. After-commit listeners and deferred queue submission execute outside that rescue and can fail at host transaction commit; worker failures are queue concerns. Do not turn these into automatic resend advice. Require matching resolved database/schema/table identity, including default and named connection targets, rather than merely equal package configuration strings.
3. **Task record synchronization.** Correct the original T-01 record's stale top-level awaiting-review status. Preserve historical findings and test dates.

Root remains the sole writer. Corrected source gets focused regressions, independent re-review and an integrated gate. Documentation-only qualifications are checked against installed framework control flow; no new runtime guarantee is invented.

## Correction results and actual gate

The malformed-container guard is at the existing global-parser boundary,
shared by both send entry points. It retains an observed global code for
diagnostics without claiming that malformed output proves refusal. New
integration cases cover scalar and associative/one-based collections,
cancelled result recording, no accepted event, no resend, and compatible
absent/null/empty global-refusal controls. No refusal code or public retry
decision changed.

| Check, 2026-11-05 | Result |
|---|---|
| Correct red run before fix | 7 failed, 61 passed, 396 assertions; seed 1793870696 |
| Corrected focused T-02/T-01/T-09 suite | 130 passed, 880 assertions; seed 1793870746 |
| Integrated gate after correction | 209 passed, 1151 assertions; seed 1793870801 |
| PHPStan level 8 | No errors |
| Pint owned files and full `--test` | Passed |
| Composer strict validation / diff whitespace | Passed |

An initial new dataset used closures with a multi-argument `mixed`
parameter. Pest leaves those closures unresolved, so those two fixture
values were encoded incorrectly. They were replaced with plain arrays
before the proper red run above. The earlier 9-failure output is not
claimed as behavioral regression evidence.

Gateway re-review accepted the correction and independently passed 26
parser assertions over 13 synthetic fixtures. The integration reviewer
accepted the immediate/deferred-dispatch and resolved-database
qualifications across docs/comments. Reviewer execution did not include
the full suite or provider calls. T-15/T-20 had no new blocker; T-01's
strict whitelist and whole-batch recovery logic remain accepted.

**Intent/invariants and recovery:** malformed output must not authorize a
resend. Unknown results remain locally failed without claiming refusal;
storage failure retains unsafe-resend advice and emits no result event.
Immediate listener isolation does not contain host after-commit callbacks
or queue-worker failures. Root's Pest suite uses synthetic HTTP fixtures;
live schemas, real database concurrency and release readiness remain open.

**Artifacts/reviewer focus:** gateway/global parser,
`BulkPreparationAndCorrelationTest.php`, `docs/events.md`, CHANGELOG and
the synchronized T-01/T-09 records. The historical November 2 acceptance
remains recorded; this dated correction closes the newly found gap.

## Remaining pathway

D2/D3/D4 are unanswered in the decision log; a combined question is pending. T-03 remains the next core packet once D3 is selected, followed by T-04/D2 and T-13. T-06 needs D4, T-12 needs D5 and T-18 needs D7. T-07 and T-08B remain owner/evidence work. Unblocked T-11 draft, stable portions of T-16 and T-17 workflow hardening can proceed with their evidence limitations explicit; none establishes release readiness.

## Continuation completed for this handoff

- **T-11:** provisional public documentation implemented and accepted in
  scoped review. Provider support remains T-07-gated; see
  `T-11-dlr-support-documentation.md`.
- **T-16A:** 20 stable-surface conformance cases added; independent review
  improvement to JSON-value assertions applied; bounded slice accepted.
  Full T-16 remains open; see `T-16A-stable-surface-coverage.md`.
- **T-17:** six-row CI workflow hardening prepared and reviewed, YAML
  parsed, required local Composer options confirmed. Offline lowest
  resolution stopped for unavailable metadata; actual matrix/audit
  execution is unverified. See `T-17-ci-hardening.md`.

Final local gate on the resulting source/test/workflow tree: **229 passed,
1245 assertions**, seed 1793871865, reported duration 13.99 s. PHPStan level
8, Pint, Composer strict validation and diff whitespace checks pass. No
provider call, dependency update, commit, push, deployment operation or
secret rotation occurred. The [current pathway status](2026-11-05-execution-status.md)
is the next engineers' status index; the November 2 records remain history.
