# Sequential Execution Directive - Remaining Reliability and Release Work

> **November 9 scope amendment:** the owner approved passive D3 storage,
> one cURL 6/7 send retry and an authenticated configurable 600/minute
> limiter. The former T-03 state machine, delivery events/actions and
> polling/reconciliation gate are superseded. T-04 retry scope is a bounded
> slice; credential/balance improvements remain separate. See
> [approved resolutions](../implementation/2026-11-09-approved-minimal-resolutions.md)
> and [current status](../implementation/2026-11-09-execution-status.md).

**Date:** 2026-11-02, Africa/Nairobi. **Coordinator/implementer:** Codex. **Authority:** owner's instruction to cross-validate and take charge of pending packets sequentially, the November consolidation, adversarial AR constraints and `.agents` directives. No commit, push, live provider call, history rewrite or release publication is authorized by this document.

## Accepted foundation

T-08A, T-05 and corrected T-01 are accepted within their recorded scopes. The coordinator then implemented and accepted T-02 (atomic preparation/correlation), T-09 (serialization/listener contract), T-15 (lazy channel registration) and T-20 (hygiene). Latest full gate: **191 passed, 1039 assertions**, PHPStan level 8 clean, Pint clean, Composer strict valid, diff whitespace clean. See [execution status](../implementation/2026-11-02-execution-status.md) for actual per-packet evidence and limitations.

## Next core packets, one writer and one accepted handoff at a time

| Order | Packet and prerequisite | Concrete acceptance boundary |
|---|---|---|
| 1 | T-03, approved D3 replacement | Store only the sanitized payload, with no state/timestamp changes, observers, SDK events or application actions; propagate storage failure. |
| 2 | T-04, approved bounded D2 slice | One retry for structured cURL 6/7 only; no response/ambiguous retries or send redirects. Remaining credential/balance work is separate and still pending. |
| 3 | T-13 | Associate the notifiable through its morph relation across all senders; integer and configured UUID/ULID keys; alias and query behavior; restore morph-map AND require-map state in tests. |
| 4 | T-12, after D5 | Framework migration publishing with approved publish semantics; fresh schema changes and a safe existing-installation upgrade path; application migration-date configuration respected. |
| 5 | T-14 | Doubles match validation, complete bulk preflight, limits and listener isolation; actual ChannelManager cache invalidation on fake install; failure rows/events and passive callback parity; settle and document simulated-error scope. |

T-06 applies the approved D4 default: one configurable named Laravel
limiter, 600 authenticated callbacks/minute, with authentication first.
Exercise the actual shipped default, override, shared bucket, authentication
priority and env-backed secret. Do not use empty test middleware as evidence.

## T-03 replacement acceptance cases

The approved passive policy supersedes the prior state machine and
concurrency/precedence cases. Verify payload-only writes across varied
statuses and repeated/reordered reports; preserve all timestamps and state.
Suppress retrieval/write observers and emit no SDK events. Verify configured
model connection and JSON casts, sanitized ingress, unmatched acknowledgement,
and propagation of storage failures. No poller, lock/retry engine, history
store or application reconciliation is required for informational storage.

## Decision and evidence gates

- D2/D3/D4 were explicitly approved on November 9 and are recorded in the
  consolidation decision log. D1-A remains selected. D5 migration publishing,
  D6 privacy and D7 support-window choices remain open.
- D6/privacy choices, any history rewrite, and decisions about publishing internal reviews remain owner-controlled. Export exclusions do not remove public Git history. Keep `.docs` uncommitted pending that decision.
- T-07 is owner-run live contract capture. Obtain sanitized exact request/response fixtures for reference echo, provider ID scope, duplicate suppression, balance, getdlr and callback method/schema/retries/source IPs. Do not fabricate successful captures. Preserve conservative unknown classification for system errors until evidence supports a change.
- Rotate `TEXTSMS_DLR_SECRET` in affected deployments and assess retained rows/logs; no deployment access or historical scrub was performed.

## Readiness and delivery path

After the core/integration packets, finish T-11 support documentation from T-07 evidence ; T-10 active reconciliation is deferred outside the approved passive scope. T-16 coverage follows final behavior. T-17/T-18 require actual lowest/stable dependency graphs and supported PHP/Laravel matrix evidence; do not widen constraints from version arithmetic. T-19 reconciles all public recipes/checklists against accepted behavior. T-21 verifies the exact release archive, fresh installs, supported real databases and provider integration before authorized release operations.

Every nontrivial packet gets its own plan, tests that reproduce the defect, implementation, scoped independent review, accurate documentation and final gate. Existing reviewers may be reused; no concurrent source writers. Read-only review acceptance and root-run test evidence remain separately identified. No outbox, new policy framework, feature sender or retry command is included without a demonstrated requirement and separate scope.
