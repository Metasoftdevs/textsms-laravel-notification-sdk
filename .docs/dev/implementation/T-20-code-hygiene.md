# T-20 - Code and Command Hygiene

**Date:** 2026-11-02, Africa/Nairobi. **Status:** accepted after independent bounded review and integrated gate. **Owner:** Codex.

## Planned scope

Remove PHPStan's nonexistent stub exclusion and describe its existing generic-type suppression accurately. Fill public implementation PHPDoc gaps through the owning contract where appropriate, without inventing guarantees for unimplemented T-03/T-04/T-14 behavior. Correct the provider-code docblock against the bundled PDF and qualify accepted results as usable success responses, not code alone. T-01 already caps response descriptions; real database enforcement remains T-21.

Make `textsms:test`'s default UTC marker actually use UTC regardless of application timezone. Its error prefix must not turn unknown/local failures into claimed provider refusals. Write meaningful command regressions first. Document that Foundation/Queue integration requires a full Laravel application; no standalone Illuminate-only support or untested dependency widening is introduced.

Run focused command tests, the integrated suite, PHPStan, Pint, Composer strict validation and whitespace checks, then an independent bounded review. No provider calls or commits.

## Implemented behavior and verification

The command converts the current instant to UTC before formatting its default marker. Its generic exception prefix is now `SMS dispatch failed`, preserving the exception's actual certainty rather than asserting provider rejection. The explicit body option and exit statuses are unchanged. PHPStan no longer excludes a nonexistent path, and its existing generic-type suppression is described accurately. Source comments explain their public boundary or inherit its contract. Provider code meanings match the bundled PDF; acceptance requires more than code 200.

The README documents full `laravel/framework` runtime dependence for Foundation and Queue integration. Composer constraints and dependencies were not widened. The T-01 description-length cap remains in place; SQLite and comments are not evidence of real database length enforcement.

| Check, 2026-11-02 | Actual result |
|---|---|
| Command regressions before fix | 2 failed, 5 assertions; seed 1793644204; observed Nairobi time falsely labeled UTC and wrong rejection prefix |
| Focused command tests | 2 passed, 7 assertions; seed 1793644266 |
| Full suite | 191 passed, 1039 assertions; seed 1793644301 |
| PHPStan level 8 | No errors |
| Pint source/new test and full `--test` | Passed |
| Composer strict validation / diff whitespace | Passed |

Independent source review accepted the UTC conversion, neutral prefix, proportional comments/config cleanup, provider meanings and explicit runtime limitation. The reviewer did not run tests. Test fixtures use synthetic recipients and a mocked sender; no HTTP request was sent.

**Intent/invariant:** operator-facing time and failure language must describe the actual instant/outcome. Comment-only cleanup does not silently implement pending retry, DLR, fake or schema behavior.

**Failure/recovery:** package exceptions retain their own message and structured provider details; the command exits with failure without claiming refusal. Host timezone is restored in the regression test's `finally` block. Production database/provider/compatibility evidence is still required by T-07/T-18/T-21.

**Artifacts/reviewer focus:** command and `tests/Feature/TestSendCommandTest.php`, `phpstan.neon.dist`, README, targeted source PHPDoc and CHANGELOG. No new abstraction, runtime dependency or broad suppression was introduced.
