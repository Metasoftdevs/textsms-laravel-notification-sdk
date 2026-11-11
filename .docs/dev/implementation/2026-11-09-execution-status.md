# Implementation pathway status — November 9

> Historical snapshot. Current implementation and release gates are in
> [November 11 status](2026-11-11-execution-status.md).

**Date:** 2026-11-09, Africa/Nairobi. **Coordinator/implementer:** Codex.
**Checkout:** accumulated uncommitted work; no commit/push/deployment.
This supersedes the [November 5 snapshot](2026-11-05-execution-status.md)
without rewriting its historical test evidence.

## Approved scope and current result

The owner explicitly resolved D2/D3/D4: one cURL 6/7 send retry, passive
delivery-report storage and a configurable authenticated 600/minute limiter.
The previous delivered-final proposal and callback-driven domain actions
are superseded. Polling/reconciliation is deferred outside the passive scope.

All three approved slices are implemented in order **D3 → D2 → D4** and
accepted after scoped independent source/documentation reviews. The integrated runtime gate
passes: **281 tests, 2117 assertions**, random seed **1794253104**, up from
229/1245 on November 5. PHPStan level 8 and Composer strict validation pass.
Style/whitespace checks pass. Full evidence and review limits are recorded in the
[integration record](2026-11-09-approved-minimal-resolutions.md).

| Packet / decision | Current disposition |
|---|---|
| D1 / T-01 | Accepted earlier; outbound listener/result isolation retained. |
| T-02 | Accepted earlier; atomic preparation/correlation and conservative unknown handling retained. |
| D3 / T-03 replacement | Implemented and reviewed: only sanitized `dlr_payload` changes; no state/timestamps/observers/events/actions. [Record](T-03-passive-delivery-reports.md). |
| D2 / T-04 retry slice | Implemented and reviewed: one additional Laravel HTTP attempt only for structured cURL 6/7; send redirects disabled. [Record](T-04-bounded-send-retry.md). |
| T-04 remainder | Still pending: credential preflight and balance response/error classification. Current balance behavior deliberately unchanged. |
| T-05 | Accepted earlier; ingress shape validation and secret sanitization retained. Historical rotation/cleanup is owner-run. |
| D4 / T-06 | Implemented and reviewed: named shared limiter, env default600, authentication first, existing env-backed URL secret. [Record](T-06-authenticated-callback-limiter.md). |
| T-07 | Owner/live evidence pending for outbound/reference/ID/idempotency/balance contracts and any actual callback integration. No live captures fabricated. |
| T-08A | Previously accepted preliminary export exclusions; exact release artifact still needs T-21 verification. |
| T-08B / D6 | Privacy/disclosure choices pending; no internal-doc commit, visibility change or history rewrite. |
| T-09 | Serialization and outbound listener contract retained; legacy delivery class stays compatible, default delivery emission removed by D3. |
| T-10 | Deferred outside approved passive callback scope; no active reconciliation/poller requirement or implementation. |
| T-11 | Provisional provider-support documentation retained; final live claims still require T-07. Updated for passive behavior. |
| T-12 / D5 | Migration publish/upgrade work pending approved D5 semantics. |
| T-13 | Pending: morph relation association and configured identifier keys across senders. |
| T-14 | Pending: double validation, bulk preflight, manager cache/event fidelity and passive callback parity. No active delivery simulation required. |
| T-15 | Previously accepted lazy notification-manager registration retained. |
| T-16 | Stable-surface T-16A slice accepted earlier; new approved-behavior regressions added now. Overall coverage work remains open, adjusted to passive scope. |
| T-17 | Prepared workflow reviewed earlier; actual dependency matrix/audit execution remains pending. |
| T-18 / D7 | Support-window choice and real dependency graphs pending; no Laravel-major expansion claimed. |
| T-19 | Current affected docs/contracts aligned; final package-wide truthfulness/recipe sweep remains pending after remaining implementations. |
| T-20 | Earlier accepted hygiene retained. |
| T-21 | Release still pending artifact/fresh-install/real-DB/provider/matrix evidence and separately authorized release operations. |

## Remaining implementation pathway

1. Complete the original T-04 credential/balance remainder as its own bounded
   packet; do not reopen approved retry/DLR policy or add a retry framework.
2. T-13 morph association, then T-12 after D5, then T-14 double fidelity.
3. Finish T-16 coverage against those accepted behaviors, execute T-17's
   real matrix/audit and settle T-18 only after D7 and dependency evidence.
4. Finalize T-11 from actual T-07 evidence, complete T-19 documentation and
   T-21 artifact/integration/release checks. T-08B remains gated by D6.

One source writer implements each packet and obtains read-only adversarial
review before accepted handoff. D5/D6/D7 are still owner choices; no approval
is inferred. The owner also retains live-contract capture and affected
environment `TEXTSMS_DLR_SECRET` rotation/retention decisions.

## Milestones and limits

M0 remains incomplete because D5/D6/D7 are open. M1 remains incomplete
because the original T-04 remainder and T-08B are open. M2/M3 remain incomplete
because integration, supported dependency graphs, live provider evidence and
release execution are outstanding. Passive reports remove the old active
delivery state machine/poller dependency rather than marking it implemented.

Tests use synthetic HTTP fixtures and SQLite. The retry cap is at Laravel's
layer, not a provider exactly-once guarantee. Limiter tests use array cache;
Redis order is source-reviewed only. Existing host published configs/routes
need updating, including removal of old `throttle:60,1` if only the new limit
is wanted. No host configuration, live secret, database history or deployment
was changed.
