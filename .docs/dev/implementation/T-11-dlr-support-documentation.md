# T-11 - Delivery-report support documentation

> November 9 amendment: D3/D4 are now approved and implemented. The old
> cancelled-update/throttle notes below describe the November 5 snapshot.
> Current callbacks are passive and event-free, with authenticated named
> limiting. Live provider evidence remains pending; see [current status](2026-11-09-execution-status.md).

**Date:** 2026-11-05, Africa/Nairobi. **Status:** provisional draft implemented and checked; final provider-support acceptance needs T-07. **Owner:** Codex.

## Planned changes and boundary

Describe the actually shipped POST endpoint separately from the unverified
provider push contract. The extraction history reports callbacks in QPIMS;
this checkout has no sanitized live fixtures proving current provider
method, schema, registration, retries, signatures or source IPs. Make portal
setup and callback testing conditional on account/provider confirmation.

Update README, `docs/webhooks.md`, operations, the config comment and
controller PHPDoc. Remove the unsupported retry-loop inference and the
claim that provider signing capabilities are known. State that a lookup
operation is provider-documented but this SDK has no polling command or
scheduled reconciliation yet. Do not add a scheduling recipe for an absent
command. Correct the adjacent README failed-event description to match
accepted refusal/unknown semantics. Preserve the actual throttle and known
cancelled-save gap until D4/T-03; do not silently select those decisions.

Verification uses route/source/command inventory and focused existing
webhook/event tests, syntax/style checks, whitespace review and independent
documentation review. No test or local text edit proves live delivery.
No provider requests, credentials, endpoint behavior or Composer constraints
change. T-07 remains the final evidence gate for provider guarantees.

## Implemented draft and evidence

The listed public documents/comments now distinguish the actual POST SDK
route from inherited QPIMS push observations and missing live fixtures.
Callback registration, method/schema, retries, source IPs, custom headers
and signatures require account/provider confirmation. The documented lookup
operation is not presented as a shipped poller. The README diagram labels
push as conditional; its failed-event row now includes unknown acceptance.
The response table calls out the existing cancelled-update gap and normal
exception propagation. Operations includes unconfirmed push and early
unmatched reports among diagnostic possibilities.

Independent read-only review found one remaining rotation instruction
claiming a known portal procedure and treating 401 as proof of propagation
delay. That wording was corrected to account-confirmed registration and
authentication failure with several possible causes. No throttle value,
route method, persistence policy or other runtime behavior changed.

| Check, 2026-11-05 | Actual result |
|---|---|
| Existing webhook/event tests | 37 passed, 171 assertions; seed 1793871065 |
| PHP syntax, config and controller | No errors |
| Pint on touched PHP/comment files | Passed |
| `git diff --check` | Passed |
| Source inventory | POST route; shared-token middleware; three registered commands, no poller |

The latest preceding runtime integrated gate is 209 tests / 1151
assertions, recorded in `2026-11-05-cross-validation.md`. This provisional
draft changed prose/PHP comments only and does not claim a new live test or
provider-support acceptance. No extra behavioral test was invented for
documentation-only edits.

**Intent/invariant and recovery:** users must know whether a behavior is
SDK-implemented, historically reported or live-provider-confirmed. A 200
response alone does not establish persistence/delivery; a failed/unknown
state does not establish safe resend. Verify account behavior and retain
sanitized evidence before relying on callbacks.

**Artifacts/reviewer focus:** README, webhooks, operations, config comment,
controller PHPDoc and CHANGELOG. Finalize support assertions only after
T-07 evidence; add polling scheduling only if T-10 is actually implemented.
