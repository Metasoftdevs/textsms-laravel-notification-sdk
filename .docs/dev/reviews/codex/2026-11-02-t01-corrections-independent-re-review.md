# T-01 Corrections - Independent Re-review

**Date:** 2026-11-02, Africa/Nairobi\
**Checkout:** `main` at `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`, plus uncommitted first-wave and correction changes\
**Reviewers:** Codex and three independent read-only review agents\
**Scope:** re-review of [W1-01 through W1-04](2026-11-02-first-wave-independent-review-and-next-actions.md) against [the correction record](../../implementation/T-01-review-corrections.md). No runtime implementation edits or commits were made during re-review. No provider or connector operations occurred.

## 1. Disposition

| Finding | Re-review result |
|---|---|
| W1-02 - failing reporter or recovery logger | Accepted within the gateway publication/recovery boundary. Reporter failures are contained; the recording exception is constructed before best-effort logging and retains its original cause. |
| W1-04 - constructor-time preparation failure | Accepted. Construction, attribute filling, notifiable initialization, and save are inside the preparation boundary. |
| W1-03 - overbroad recording documentation | Substantive correction accepted. Public docs now scope the guarantee to outbound sends, explain partial bulk publication, and disclose the DLR save-veto limitation. Synchronize the stale helper description in the original T-01 task record during the remaining correction. |
| W1-01 - uncertain provider output described as rejection | **Partially corrected; P1 remains open.** HTML, unmatched, missing-code, and stray top-level-200 cases now retain uncertainty, but arbitrary nonempty codes still become confirmed rejection. |

**T-01 remains changes requested. T-02 implementation remains pending the accepted T-01 handoff.** T-08A and T-05 retain their previously scoped acceptance. No owner decision was changed; D1-A stays in force.

## 2. Remaining blocker: rejection recognition is too broad

**Source:** `src/Gateways/TextSmsGateway.php:413-415`:

```php
return $code !== '' && $code !== '200' ? 'rejected' : 'unknown_response';
```

This predicate recognizes any nonempty non-200 value as rejection. It does not recognize a documented TextSMS send rejection, despite the correction's claim. A syntactically valid response can contain an unknown or corrupted code without establishing whether TextSMS accepted the request.

The remaining behavior is the same recovery-certainty problem as W1-01, rather than the broader normal provider-exception wording already assigned to T-02.

**Freshly reproduced with faked HTTP 200 and a model `updating` listener that returns false:**

| Reply and operation | Actual recovery result |
|---|---|
| Bulk top-level `response-code: "garbled"` | Exception says TextSMS had rejected the whole batch; both recovery entries say `provider_acceptance = rejected`; both rows remain pending; no unsafe-resend warning. |
| Matched bulk rows with code `9999` | Exception says TextSMS rejected every dispatch; recovery entries again say rejected; both rows remain pending. |
| Single response row with code `"garbled"` | Exception says TextSMS rejected dispatch; recovery acceptance is rejected and the row remains pending. |

Each probe made exactly one provider request. None established an actual provider rejection. An operator following these messages could resend an already accepted SMS.

### Required focused correction

1. Recognize confirmed rejection using an explicit documented send-rejection policy at the existing `rejectionKind()` boundary. Unknown code values must remain `unknown_response`. A code's presence in a cross-operation provider table does not automatically make it evidence of send rejection; for example, `1008` describes no delivery report.
2. Preserve known acceptance and confirmed rejection behavior. Recovery contexts for unrecognized codes must say `provider_acceptance = unknown`; exception messages must say the SMS may have been accepted and resending automatically/the whole batch is unsafe.
3. Add permanent cases for top-level and matched-row unrecognized codes, including a corrupted string and an undocumented numeric value. Cover single and bulk entry points. Retain the documented rejection control case.
4. Add or extend regression cases for missing codes, stray top-level 200, and mixed accepted/unknown/rejected bulk counts. Inspect whole-call certainty, including rows whose recording succeeded, when deciding whether blanket resend is unsafe.
5. Synchronize the original T-01 task record's remaining `rescue(..., report: true)` description with the actual separately contained reporting helper. Preserve historical test dates.

One gateway writer owns `TextSmsGateway.php`, the lifecycle regressions, and the proportional correction documentation. Keep changes private to the current outcome/recovery boundary; no parser framework, new public contract, status redesign, retry change, DLR policy change, or live call is required. Do not defer this predicate fix into T-02.

## 3. Fresh execution evidence

All results below were obtained during this re-review on 2026-11-02:

| Check | Result |
|---|---|
| `php vendor/bin/pest --no-coverage --compact` | **106 passed, 509 assertions**, seed 1793570007, reported duration 10.96 s |
| `php vendor/bin/phpstan analyse --no-progress` | No errors, configured level 8 |
| `php vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Valid |
| `git diff --check` | Clean |
| Ten focused in-memory probes | Script exited 0; three rejection-recognition counterexamples above and seven confirming cases below |

The seven confirming cases were:

- Missing-code single response -> unknown recovery acceptance and unsafe-resend wording.
- Stray top-level code 200 -> unknown recovery acceptance and unsafe-resend wording.
- Documented code 1004 -> rejected recovery acceptance, with rejection details retained.
- Unparseable bulk response -> unknown recovery acceptance and unsafe blanket-resend wording.
- Accepted send with a throwing real exception reporter -> sent row returned and persisted.
- Accepted send plus recording failure and throwing recovery logger -> package exception, code 200, original storage cause preserved.
- NaN metadata during construction -> package preparation exception with `JsonEncodingException` cause, zero rows, zero HTTP requests.

The probes ran through standard input with autoloaded package/framework classes and Testbench's in-memory database. HTTP was faked and stray requests prohibited. No probe file, live provider request, or runtime source edit was created. The suite covers the corrected accepted/failed/bulk/DLR reporter cases; the probes independently confirmed the principal boundaries and exposed the remaining code-recognition gap.

The implementer's three earlier seeds and red-before-green history remain historical implementation evidence. This re-review does not independently establish their edit chronology. No dependency update/audit, real-database smoke, deployment check, or release archive execution was performed.

## 4. Accepted limits and next handoff

- Best-effort diagnostics can leave no trace when their sink fails. That limitation is disclosed and does not itself reopen the gateway send-result contract.
- Other existing logging paths, including the channel's warning logger and DLR warning/info logs, are outside the corrected gateway publication/recovery boundary. A globally broken logger can still affect those paths; this review does not claim complete application-wide diagnostic isolation.
- Normal exceptions after successfully recording unknown provider output still use the old rejected wording. T-02 must align that wording with the established certainty model.
- Unknown attempts still persist as failed; unmatched-response diagnostics remain T-02 work. DLR save veto and its failure response remain explicit T-03 acceptance work. Retry policy remains D2/T-04 work.

**Next:** implement the single rejection-recognition correction, synchronize the task record, run focused regressions and the integrated gate, then obtain a focused independent review. Once W1-01 is accepted, T-01 can be accepted and the already planned T-02 handoff can proceed. Owner decisions D3/D2/D4, T-07 contract capture, secret rotation, and release checks remain separate open work.
