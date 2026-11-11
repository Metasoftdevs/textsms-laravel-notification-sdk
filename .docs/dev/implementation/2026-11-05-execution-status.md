# Implementation pathway status

> Historical November 5 snapshot. Current decisions and pathway status are
> in [November 9 status](2026-11-09-execution-status.md). D2/D3/D4 were
> approved on November 9; delivered-final, application reconciliation and
> poller requirements were superseded by passive informational storage.

**Date:** 2026-11-05, Africa/Nairobi. **Coordinator/implementer:** Codex.
**Checkout:** `main@f6b7627` plus accumulated uncommitted changes. This
index supersedes the November 2 status without changing historical gates.

## Cross-validation result

T-01's second correction is accepted: only the nine documented refusal
codes establish rejection; system/other-operation/undocumented codes remain
unknown. Whole-batch recovery includes recorded and unrecorded rows.
Listener/reporter containment and preparation/recording boundaries match
their scoped contracts.

Three read-only reviewers rechecked source and records. A new T-02
malformed-container fallback defect was reproduced and fixed with tests
written before the fix. T-09 immediate/deferred listener guarantees and
resolved database identity were qualified. Independent re-review accepted
both corrections. Detailed evidence is in
[cross-validation](2026-11-05-cross-validation.md).

**Final local gate:** 229 tests passed, 1245 assertions; seed 1793871865.
PHPStan level 8, Pint, Composer strict validation and diff whitespace pass.
Tests use synthetic HTTP fixtures; live provider, real-engine concurrency
and compatibility-matrix evidence remain separate gates.

## Packet status against the November plan

| Packet | Status | Next gate / evidence |
|---|---|---|
| T-01 | Accepted | Final W1 correction retained; November 5 source recheck found no new blocker. |
| T-02 | Accepted after supplementary correction | Malformed scalar/non-list response collections remain unknown; fresh regressions/re-review/full gate. |
| T-03 | Next core packet, awaiting D3 | Approved DLR precedence, atomic transitions, stable first timestamps and confirmed persistence before events/acknowledgement. |
| T-04 | Awaiting T-03/D2 | Operation-specific retries, outbound credential guards and truthful balance classification. |
| T-05 | Accepted within built-in ingress scope | Historical secret rotation/retention cleanup remains an owner operation. |
| T-06 | Awaiting D4 | Current default remains `throttle:60,1`; tests must exercise actual shipped defaults. |
| T-07 | Owner/live evidence pending | Sanitized exact reference echo, idempotency, ID scope, balance, lookup and push-contract captures. |
| T-08A | Accepted within preliminary archive scope | Exact intended release archive still needs T-21 verification. |
| T-08B | Awaiting D6 | Privacy/disclosure/history decisions; no visibility/history change or internal-doc commit. |
| T-09 | Accepted with documented limits | Current-model/historical-event semantics; after-commit/worker failures are host-owned; same resolved DB identity required. |
| T-10 | Not implemented; provider-evidence dependent | T-07/D3 and a supported reconciliation contract; conditional release-candidate requirement. |
| T-11 | Provisional draft implemented and reviewed | Final provider-support acceptance awaits T-07. No polling command advertised as shipped. |
| T-12 | Awaiting D5 and integration sequence | Migration publishing/schema/upgrade safety; no repeat-safe publishing claim. |
| T-13 | Queued after T-04 | Morph relation association across all senders; configured owner keys and restored morph-map test state. |
| T-14 | Queued after report/morph work | Double fidelity, full bulk preflight, isolated events, simulated outcomes and real manager cache invalidation. |
| T-15 | Accepted | Lazy manager registration and both resolution paths remain valid. |
| T-16A | Accepted bounded slice | 20 new normalizer, binding, preboot route and Artisan cases; see its task record. |
| T-16 overall | Open | Pending behavior/doubles/poller and negative console boot coverage; no complete-coverage claim. |
| T-17 | Workflow prepared/reviewed, execution pending | Six Laravel 12/PHP/stability rows, strict validation/audit; lowest offline probe lacked metadata, actual matrix unverified. |
| T-18 | Awaiting D7 and real graphs | No Laravel 13/PHP 8.5 expansion or compatibility claim. |
| T-19 | Final sweep pending | Targeted truthfulness fixes landed with T-11; final public checklist/recipe consistency follows accepted behavior. |
| T-20 | Accepted | UTC marker, neutral command errors, PHPDoc/static-config hygiene and full-framework requirement retained. |
| T-21 | Release pending | Exact artifact, fresh installs, real DB/provider smoke, matrix evidence and separately authorized release operations. |

## Execution order and unanswered choices

Core order remains **T-03 (D3) -> T-04 (D2) -> T-13 -> T-12 (D5) -> T-14**.
T-06 follows D4 independently. A combined D2/D3/D4 question is pending;
recommended choices are delivered-final, retries only for proven cURL 6/7
pre-send failures, and no default callback throttle. These are proposals,
not recorded owner answers. D5/D7 remain open as well. D1-A is still the
only selected decision. No behavior was silently selected while waiting.

One writer implements each packet, reproduces defects where applicable,
obtains independent review and runs appropriate gates before handoff.
Existing accumulated work is preserved. No commit/push, live provider call,
deployment or secret rotation was performed. The owner still handles
T-07, D6 and affected-environment DLR-secret rotation/retention decisions.

## Milestones

| Milestone | Current disposition |
|---|---|
| M0 decisions | Incomplete: D2-D7 are open. |
| M1 P0 complete | Incomplete: T-03/T-04/T-06 and T-08B remain open. |
| M2 release candidate | Incomplete: M1, live/provider evidence, integration and support gates remain. |
| M3 stable | Incomplete: full coverage, real matrix/audit, documentation and release execution remain. |

The prepared CI files can be reviewed now, but no remote execution or green
lowest/PHP 8.3/8.4 result is claimed. Source acceptance does not establish
provider idempotency, exactly-once sending, durable event publication or a
release-ready artifact.
