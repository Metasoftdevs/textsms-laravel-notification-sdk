# T-03 replacement — Passive delivery-report information

**Date:** 2026-11-09, Africa/Nairobi. **Owner:** Codex.
**Status:** accepted within the approved replacement scope after independent
source/documentation reviews and the integrated local gate.

## Problem, objective and approved boundary

The old recorder classified reports, changed submission state/timestamps
and fired delivery events. Reordered reports could change domain behavior
through application listeners. The owner replaced the proposed precedence
engine with informational storage: only the sanitized latest `dlr_payload`
is written. No outcome wins, application action runs or new schema is needed.

Preserve status, identifiers, counters and all timestamps, including
`updated_at`. Keep authenticated/validated ingress, JSON casting, configured
model connection and unknown-ID acknowledgement. Propagate storage failures
rather than claim success. No poller, reconciliation engine, history table,
lock/retry mechanism or resend summary/counter is part of this packet.

## Implementation and compatibility

`TextSmsGateway::recordDeliveryReport()` scopes lookup and the payload write
inside Eloquent `withoutEvents()` and `withoutTimestamps()`. Retrieval hooks
are suppressed as well as saving/updating hooks so the callback cannot start
application observer work. Only `dlr_payload` is updated. Exceptions propagate;
a false save result also throws. The old classifier and delivery emission
are removed. Native scope cleanup restores normal model behavior afterward.

Outbound accepted/failed publication is unchanged. `SmsDeliveryReported`
remains loadable/serializable for source and queued-event compatibility, but
no new callback emits it. Old queued jobs remain host-owned. Legacy delivery
columns/statuses remain readable; there is no backfill. The legacy
`awaitingDlr()` query is unchanged and documented: use `whereNull('dlr_payload')`
to find missing callback information under this policy.

The processor contract, event/model/controller docblocks, README, events,
webhooks, architecture, operations and provider-extension guidance now
describe this passive behavior. Old state-changing webhook/gateway tests
were updated deliberately; serialization tests retain the compatibility class.

## Actual verification, November 9

| Check | Result |
|---|---|
| Correct red run, new passive tests | 6 failed, 1 passed; 26 assertions; seed 1794252043 |
| Focused passive green | 7 passed, 62 assertions; seed 1794252072 |
| Integrated final runtime suite | 281 passed, 2117 assertions; seed 1794253104 |
| PHPStan level 8 | No errors |
| Independent source review | `nov05_integration_crosscheck` accepted passive scope; no independent test execution |

An initial helper assertion compared an unhydrated database default and was
corrected before the proper red run; it is not regression evidence. The first
full integration run also exposed two old delivery-transition expectations;
they were aligned with the approved behavior before the passing final run.

Tests cover delivered/failed/interim strings, duplicates/reordering, throwing
retrieval/write observers, unchanged timestamps/state, an alternate dispatch
connection, sanitized direct input and a database write failure returning 500.
These are synthetic SQLite/HTTP tests, not live callbacks or real-engine
locking evidence. Unmatched/early reports can still be acknowledged without
storage; this is explicitly informational, without automatic repair.

See [approved scope](2026-11-09-approved-minimal-resolutions.md) and
[current pathway](2026-11-09-execution-status.md). No commit, deployment,
provider request, secret rotation or historical data cleanup occurred.
