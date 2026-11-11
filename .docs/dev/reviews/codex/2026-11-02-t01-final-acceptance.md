# T-01 Final Acceptance

**Date:** 2026-11-02, Africa/Nairobi. **Checkout:** `main@f6b7627` plus the uncommitted first-wave implementation and two correction passes.

The second W1-01 correction is accepted. `rejectionKind()` uses strict membership in the private send-refusal code list instead of a nonempty/non-200 test. The list and its conservative exclusions were cross-checked against the bundled provider PDF, page 4. System-error and other-operation codes remain unknown; no live provider behavior is inferred and no owner policy decision is changed.

Permanent regressions cover corrupted and undocumented codes in single/bulk and top-level/per-message responses, known refusals, missing codes, stray top-level 200, and whole-batch certainty when accepted/unknown rows were successfully recorded. The original task record's reporting-helper descriptions are synchronized.

Fresh gate on 2026-11-02:

| Check | Result |
|---|---|
| `php vendor/bin/pest --no-coverage --compact` | 129 passed, 669 assertions; seed 1793626460; reported duration 60.64 s |
| `php vendor/bin/phpstan analyse --no-progress` | No errors, level 8 |
| `php vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Valid |
| `git diff --check` | Clean |

All four W1 findings are accepted within their documented scope. **T-01 is accepted and T-02 may start.** T-08A/T-05 retain their scoped acceptance; first-wave engineering work is accepted. This is not release acceptance or authorization to commit/publish.

Unchanged carryovers: truthful normal exceptions and unmatched diagnostics belong to T-02; DLR save veto/transition policy belongs to T-03; retry policy belongs to T-04. Live provider evidence, operational secret rotation, exact release archive, real-database enforcement, compatibility matrix, D6/privacy decisions, and release execution remain open. Earlier review findings and test dates are retained as historical evidence.
