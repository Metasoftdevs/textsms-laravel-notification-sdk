# Reliability policy decisions — D2, D3, D4 (resolved) and the open decisions

**Date:** 2026-11-10 (Africa/Nairobi)
**Author:** Claude (consolidation of the owner discussion, 2026-11-02 → 2026-11-09)
**Audience:** the project owner, engineers, and reviewers who need one
place to see what each policy is, why it was chosen, and what it costs.
**Current status (November 11):** D1–D4 approved and implemented. Under the
owner's instruction to resolve the remaining release work, D5 uses native
publish-once migrations and D7 targets Laravel 12/13. D6 folder disclosure
is approved; current-file redaction is complete and the owner accepts retaining
the recipient in history. Live tests are owner-deferred until after publication.
Q8 remains an application adoption decision. The original problem
statements below retain their November 10 context. Current verification is
in [execution status](../implementation/2026-11-11-execution-status.md).
**Related records:** decision log in
`.docs/dev/reviews/claude/2026-11-01-consolidated-analysis-and-implementation-priorities.md` §5;
implementation evidence in `.docs/dev/implementation/2026-11-09-approved-minimal-resolutions.md`
and the T-03, T-04, T-06 task records beside it.

---

## 0. Two facts every decision below depends on

1. **Submission is not delivery.** `sent` means TextSMS accepted the
   request. `delivered` means a delivery report (DLR) says the message
   reached the handset. These are different events, and they happen at
   different times.
2. **An unknown outcome may still be a sent, billed SMS.** If no usable
   answer comes back (timeout, empty reply, unreadable body), TextSMS may
   still have accepted and sent the message. Sending is irreversible, and
   TextSMS documents no duplicate protection (no idempotency on
   `clientsmsid`).

A third fact shaped the final scope: **every `send()` call already creates
its own `sms_dispatches` row**. A resend, including one with changed
content, is a new row with its own identifiers. One row is one attempt.

---

## 1. Resolved decisions

### D2 — When may sending retry automatically?

**Problem statement.** The gateway shared one blind retry
(`retry(2, 200)`) across sending and balance lookups. It re-sent after any
non-2xx response, after timeouts, and after empty replies. If TextSMS
accepted a message but the answer was lost, the retry sent and billed it a
second time. A "connection exception" in Laravel includes read timeouts
(cURL 28) and empty replies (cURL 52), so it does not prove that nothing
was sent.

**Objective.** Recover automatically only when the request provably never
reached TextSMS. Keep every other failure visible, and never guess.

**Options considered.**

| Option | Behaviour | Duplicate risk |
|---|---|---|
| D2-A | Retry only on structured evidence that the connection was never established: cURL errno 6 (DNS) or 7 (connect refused). | None introduced by the package. |
| D2-B | Never retry a send. | None, but a DNS blip fails the send. |
| D2-C | Retry any `ConnectionException`. | Real: timeouts after acceptance are re-sent. |

**Owner direction (comparison with Laravel mail).** Laravel's mail
channel does not promise exactly-once delivery either. Synchronous mail
propagates transport errors, queued notifications retry by job policy, and
mail failover tries another transport. A small, narrowly scoped retry
budget is enough for this SDK. One difference must be kept separate: the
SMS channel catches package exceptions and returns `null`, so those
failures never become failed queue jobs and are never retried by the
queue.

**Resolution adopted — D2-A.**

- At most **two** HTTP attempts per send or bulk send (one retry).
- Retry only when the Guzzle handler context carries **errno 6 or 7**. The
  code reads that structured field, never the exception text.
- Never retry: any received HTTP response (2xx–5xx), timeouts, empty
  replies, or exceptions without handler context.
- Send redirects are disabled, so a redirect cannot add an uncounted send.
- Balance lookups keep their own existing retry, because repeating a
  read-only lookup cannot send an SMS.

**Trade-off.** Some failures that might have succeeded on a retry now need
investigation instead. That lowers availability in ambiguous cases. In
exchange, the package never assumes an irreversible action did not happen.

**Evidence.** `tests/Feature/SendRetryPolicyTest.php`, 6 cases: one retry
on structured pre-send evidence; stop after the one permitted retry; no
retry for other or unproven failures even when their text mentions "error
7"; no retry on a received response; balance retry kept separate; an
unfollowed redirect is recorded as unknown without retrying. Full gate
passes on 2026-11-10 (281 tests).

**Limits.** The cap is at Laravel's layer. Whether TextSMS deduplicates
repeated requests is unknown until live evidence exists (T-07). The rest
of the original T-04 work (credential pre-check, balance error
classification) is still open.

---

### D3 — What does a delivery report change?

**Problem statement (original).** The DLR handler could record
`delivered`, then overwrite it with a later `failed`. Repeated reports
replaced terminal timestamps, and some transitions left both
`delivered_at` and `failed_at` set. A consumer reading that contradictory
state could resend a message that had already arrived.

**How the decision evolved.**

1. **First proposal (D3-A, "delivered is final").** A precedence table per
   attempt: delivered wins over a later failed; first timestamp kept;
   atomic transitions.
2. **Owner clarification.** Resends are separate attempts with their own
   rows. Counting successful sends or keeping a "current message" summary
   is the consuming application's job. So the remaining question was only
   about contradictory reports *inside one attempt*, and whether TextSMS
   even sends them is unverified.
3. **Final minimal scope (approved).** The package only needs **outbound
   submission plus passive recording of callbacks**. A delivery state
   machine is not needed to meet that requirement.

**Objective (final).** Accept and keep the provider's report as
information, without letting it change submission state or trigger
application actions.

**Resolution adopted — passive delivery reports.**

```text
authenticate → validate shape, remove secret → find dispatch row
            → save dlr_payload quietly → 200 OK
unknown message id → 200 OK (logged)
storage failure    → error response (never acknowledged as saved)
```

- Only `dlr_payload` changes; it holds the latest sanitized report.
- `status`, `sent_at`, `delivered_at`, `failed_at` and identifiers never
  change. No automatic `updated_at` change either.
- No Eloquent observers run, including retrieval hooks, and no SDK event
  fires. `SmsDeliveryReported` stays loadable for source compatibility but
  is not emitted.
- Repeated or reordered reports simply replace the payload. No
  precedence, timestamp reconciliation, or concurrency state machine.
- Outbound `SmsDispatchAccepted` / `SmsDispatchFailed` events are
  unchanged.

**Trade-offs.**

- The package no longer drives any delivery workflow. It never sets
  `delivered`.
- A consumer that needs delivery status must read `dlr_payload` (the raw
  provider format) itself, or wait for a future opt-in feature.
- Because observers and events are suppressed, a consumer cannot react to
  a callback in real time through the package. See **Q8** below.

**Evidence.** `tests/Feature/PassiveDeliveryReportTest.php`, 5 cases:
only the payload changes; no retrieval or write observers run; repeated
and reordered reports replace information without precedence; a failed
write is not acknowledged; the configured connection is used and a direct
processor payload is sanitized. Webhook tests cover authentication,
validation and secret stripping (T-05).

**Limits.** TextSMS's real push contract (HTTP method, schema, retry on
non-2xx) is still unverified (T-07). Pull polling is deferred, not
implemented.

---

### D4 — Should the webhook impose a default rate limit?

**Problem statement.** The shipped default was `throttle:60,1`. Laravel
keys that limiter by IP, and all TextSMS callbacks come from the
provider's own addresses. A burst of more than 60 legitimate reports a
minute got HTTP 429. Whether TextSMS retries after a 429 is unknown, so
those reports could be lost.

**Objective.** Accept legitimate bursts of delivery reports while letting
each application control its own load limits.

**Options considered.**

| Option | Behaviour |
|---|---|
| D4-A | No default throttle; document opt-in throttling. |
| D4-B | Keep a throttle, but configurable and with a higher default. |

**Owner direction.** Laravel mail has no inbound callback endpoint to
copy. Keep a constrained default, but make it configurable (D4-B).

**Resolution adopted — authenticated, configurable limiter.**

- One named Laravel limiter, `textsms-dlr`, with **one shared bucket** for
  the application's TextSMS integration (not per IP).
- Default **600 callbacks per minute**, set by
  `TEXTSMS_WEBHOOK_RATE_LIMIT` (minimum 1).
- Authentication runs **before** the limiter, enforced through Laravel's
  middleware priority. Requests with a wrong or missing token consume no
  allowance. Authenticated but malformed callbacks do consume it.
- Exceeding the limit returns Laravel's standard 429.
- The existing `TEXTSMS_DLR_SECRET` is the callback credential, as a
  `?token=` query value or an `X-TextSMS-Token` header. No new credential.

**Trade-offs.** 600 a minute is a starting policy, not a measured
capacity. It allows bursts within the minute. Applications running more
than one instance need a shared cache store for one shared bucket.
Applications that already published the old config keep `throttle:60,1`
as an **additional** limit until they remove it.

**Evidence.** `tests/Feature/WebhookRateLimitTest.php`: 600 authenticated
callbacks pass under the shipped default; configured limits share one
bucket across source IPs; authentication precedes the limiter even under
priority sorting; the env-configured secret and limit are used. Redis
ordering is source-reviewed only (tests use the array cache).

---

### Resolved decisions at a glance

| ID | Decision | Approved | Implemented and reviewed |
|---|---|---|---|
| D1 | Listener exceptions are reported, never change the send result | 2026-11-01 | T-01, accepted |
| D2 | One send retry, only for structured cURL 6/7 | 2026-11-09 | T-04 retry slice, accepted |
| D3 | Delivery reports are passive: `dlr_payload` only, no events or state changes | 2026-11-09 | T-03 replacement, accepted |
| D4 | One authenticated limiter, 600/minute default, configurable | 2026-11-09 | T-06, accepted |

---

## 2. Open decisions — to resolve before publishing

Each block uses the same format so it can be decided directly.

### D5 — Migration publishing and schema (blocks T-12)

**Problem statement.** The migration ships as a `.stub`, published under
a filename built from `date()` at boot. `status` is a database `enum` that
includes `rejected`, which no code ever writes. `attempt_count` defaults
to 0, but every writer sets 1. After the first public release, changing
the creation migration will **not** upgrade databases where it already
ran. Fixing the schema later needs an upgrade migration.

**Objective.** Settle the schema and the publish behaviour before the first
public tag, while changes are still free.

**Options.**

- **A (recommended):** Laravel's `publishesMigrations()`, documented as
  "publish once"; `status` becomes `string(16)`; `attempt_count` defaults
  to 1.
- **B:** Keep the current stub and schema, and accept an upgrade migration
  later.

Note: `publishesMigrations()` re-dates the file on each publish. It does
not prevent a duplicate if someone publishes twice (verified 2026-11-01,
probe P13). "Publish once" is the framework's own contract.

### D6 — Privacy and disclosure before the repository goes public (blocks T-08B, repository visibility, Packagist)

**Problem statement.** A real test-recipient phone number appears in
tracked `docs/package-development-plan.md` (2 occurrences) and in the
history of commit `e1db5df`. Two untracked review files also contain it.
The untracked `.docs/` and `.agents/` folders hold internal reviews and
agent directives. `.claude/settings.json` holds local tool permissions.

**Objective.** Make the repository public without exposing personal data
or internal material.

**Options.**

1. Phone number: (a) keep it with the person's consent; (b) remove it from
   the current files only (history keeps it); (c) also rewrite history,
   which needs explicit authorization under `.agents/commit_directives.md`
   and is easiest before anyone else clones.
2. Internal folders: (a) commit them to the public repository; (b) keep
   them out (local only, or a separate private repository).
3. `.claude/settings.json`: local machine settings; do not commit.

**Recommendation.** Remove the number from current files at least, and
decide on history before the visibility change. Keep `.docs/` and
`.agents/` out of the public repository unless you want them public. The
archive rules already keep them out of the Composer package.

### D7 — Laravel support window (blocks T-18)

**Problem statement.** The package requires `illuminate/* ^12.0`.
Laravel's published policy (fetched 2026-11-10) shows Laravel 12 bug fixes
ended on 2026-08-13 and security fixes end on **2027-02-24**. Laravel 13
was released on 2026-03-17 and needs PHP 8.3. Today no Laravel 13
application can install this package. The installed Pest Laravel plugin
supports only Laravel 11–12, so widening support needs a re-resolved test
toolchain, not just a constraint edit.

**Objective.** Publish with a support window that outlives the first
months of adoption.

**Options.**

- **A (recommended):** `^12.0 || ^13.0`, proven by a CI matrix of Laravel
  12 on PHP 8.2–8.4 and Laravel 13 on PHP 8.3–8.5.
- **B:** Laravel 13 only.
- **C:** Laravel 12 only; the package reaches its framework's end of life
  on 2027-02-24.

### Q8 — How do consumers learn delivery status now? (new; does not block publishing)

**Problem statement.** Under D3, callbacks fire no event and run no
observers. The QPIMS migration plan (`docs/package-development-plan.md`,
steps 8–9) still says QPIMS will remove its own SMS handling and update
`receipts.sms_delivered_at` from an `SmsDeliveryReported` listener. That
listener would never run. If QPIMS adopted the SDK as planned, its receipt
delivery updates would silently stop.

**Objective.** Decide how the package's first consumer gets delivery
status before it migrates.

**Options.**

- (a) QPIMS keeps its own delivery-report handling, and adopts the SDK for
  sending only.
- (b) QPIMS reads `sms_dispatches.dlr_payload` on a schedule.
- (c) A future, opt-in delivery event in the SDK (a new packet, needing
  its own decision).

**Recommendation.** Choose before QPIMS adoption, and update the plan's
steps 8–9. This does not block publishing the package, because the
passive behaviour is documented.

---

## 3. Decision log update

| Decision | Choice | Date | Decided by |
|---|---|---|---|
| D1 | A — report listener exceptions, preserve the send result | 2026-11-01 | Owner |
| D2 | A — one retry, structured cURL 6/7 only | 2026-11-09 | Owner (chat) |
| D3 | Passive callbacks (replaces the delivered-final proposal) | 2026-11-09 | Owner (chat) |
| D4 | B — authenticated limiter, 600/minute default, configurable | 2026-11-09 | Owner (chat) |
| D5 | Native discoverable PHP migration, publish once; fresh string16 status and attempt default1; existing tables require application-owned forward upgrades | 2026-11-11 | Engineering resolution under owner's resolve-all instruction |
| D6 | Commit `.docs/` and `.agents/`; ignore local-permission `.claude/`; keep current redactions; owner accepts retaining the recipient in history | 2026-11-11 | Owner (chat) |
| D7 | Dual Laravel12/13 constraints and compatible tools; only actual completed verification rows count | 2026-11-11 | Engineering resolution under owner's resolve-all instruction |
| Q8 | open | | |
