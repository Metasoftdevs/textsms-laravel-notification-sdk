# Approved minimal gateway resolutions

**Date:** 2026-11-09, Africa/Nairobi. **Status:** accepted within approved
scope after implementation, independent reviews and integrated local gate.
**Owner:** Codex. The owner explicitly approved D2/D3/D4 in chat. This
supersedes the earlier delivered-final/report reconciliation plan.

## Approved behavior and execution order

1. **D3 / T-03 replacement:** record the sanitized latest callback in
   existing `dlr_payload` only. Preserve submission status, all existing
   send/delivery/failure timestamps and identifiers. Suppress delivery and
   Eloquent observer events, including retrieval hooks. Keep validated,
   authenticated ingress and unknown-ID acknowledgement; storage errors
   must not be acknowledged as recorded. No precedence engine, polling,
   counters, application reconciliation, schema or event-history store.
2. **D2 / T-04 bounded slice:** at most two total send HTTP attempts, retry
   only structured cURL 6/7 connection failures. Do not parse error text or
   retry responses, timeout/empty-reply or context-free exceptions. Keep
   remaining exception/diagnostic and balance behavior. No general retry
   policy abstraction or expansion into other T-04 work.
3. **D4 / T-06:** one named Laravel limiter, default 600 authenticated
   callbacks/minute for this application's TextSMS integration, configurable
   by `TEXTSMS_WEBHOOK_RATE_LIMIT`. Authenticate before consuming its bucket,
   including Laravel middleware-priority ordering. Use existing cache and
   standard 429 handling. Existing `TEXTSMS_DLR_SECRET` already supplies the
   env-configured URL `?token=` secret; reuse it rather than create another
   credential. Header authentication remains supported and tokens excluded
   from stored payload/log context.

## Owned boundaries and verification

Gateway send client and callback method, processor contract, callback
route/config, service-provider limiter registration/priority, affected
events/docs and tests. Preserve all accumulated uncommitted work. Retain
the legacy event class for source compatibility but stop default callback
emission. Outbound accepted/failed events stay intact.

Write focused regressions before each runtime change and retain actual
red/green evidence. Update old tests whose delivery-state/event assertions
are intentionally superseded. Test quiet passive writes, duplicate/reordered
reports, configured connection, storage failure; single/bulk retry counts
with real structured Guzzle causes and bounded attempts; default/override
limiter, authentication-first ordering and env-backed callback secret.
Run static/style/full integration gates and read-only independent review
before acceptance. No provider call, credential use, commit, push or
deployment operation is authorized or needed here.

## Implemented result and actual evidence

The three slices were implemented sequentially at the gateway, route/config
and service-provider boundaries. Detailed red/green and review evidence:
[T-03 passive reports](T-03-passive-delivery-reports.md),
[T-04 bounded retry](T-04-bounded-send-retry.md),
[T-06 callback limiter](T-06-authenticated-callback-limiter.md).

D3 emits no delivery or model-observer events, including retrieval hooks;
only the informational payload is saved, with no automatic timestamp update.
D2 limits Laravel HTTP retries to one extra structured cURL 6/7 attempt and
disables send redirects. D4 uses one integration bucket after authentication,
including framework middleware-priority sorting. Existing env-backed query
or header secret support is reused, tested and documented.

Independent source review accepted D3/D4 (`nov05_integration_crosscheck`)
and corrected D2 (`nov05_gateway_crosscheck`). The D2 reviewer found a new
3xx null-predicate TypeError; regression tests reproduced the bulk failure
before the nullable predicate correction. Their six independent faked
HTTP-client controls passed after the fix. Reviewer source acceptance and
root-run DB/full-suite execution are distinct evidence.

| Gate, November 9 | Actual result |
|---|---|
| Full Pest random-order runtime suite | 281 passed, 2117 assertions; seed 1794253104 |
| PHPStan level 8 | No errors |
| Composer strict validation | Valid |
| Pint on src/tests/config/routes | Two files formatted, then final `--test` passed |
| `git diff --check` | Clean |
| Trailing-whitespace scan, including new tests/task records | No matches |
| Independent documentation re-review | Accepted by `docs_release_review`; no independent tests executed |

The first full runtime pass had 279 passed / 2 failed because two old
gateway tests still expected delivered/failed transitions. Those assertions
were intentionally updated to the approved passive semantics; the final
suite above passed. Earlier harness errors are distinguished from proper
red regressions in the task records. The PHP environment emits a pre-existing
Imagick compile/load version warning; checks still complete with their
reported exit codes.

Documentation review removed the inherited blind-failover recipe that could
resend after unknown/accepted outcomes, corrected stale acceptance and
release/CI claims, and marked superseded plan packets/gates clearly. Its
final follow-up verified implementation links and distinguished unauthenticated
requests (no allowance consumed) from authenticated malformed callbacks
(allowance consumed). No findings remain in that scoped review.

No provider request, deployment, secret rotation, historical scrub, dependency
update or commit occurred. Balance/credential work from original T-04 is
still pending. Lower-level Guzzle retries, live provider guarantees, Redis
backend execution and release readiness are not established by this gate.
See [current pathway](2026-11-09-execution-status.md).
