# First-Wave Execution Directive

**Issued:** 2026-11-01, Africa/Nairobi\
**Baseline:** `main` at `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`\
**Purpose:** assign the first implementation packets and their acceptance gates. This directive does not assert that implementation has begun or that any packet is complete.

## 1. Start order and authority

Start **T-08A, T-05, and T-01** in parallel under the file ownership below. Preserve the November T-IDs; T-08A is the archive-only portion of T-08, while T-08B retains the undecided privacy/disclosure work.

Read before work:

1. The three [repository directives](../../../.agents): `absolute_task_mode.md`, `commit_directives.md`, and `doumentation_standards.md`.
2. The [November consolidation](../reviews/claude/2026-11-01-consolidated-analysis-and-implementation-priorities.md), especially the applicable decision, task packet, specification, and working rules.
3. The [adversarial plan review](../reviews/codex/2026-11-01-contextualization-and-adversarial-plan-review.md), especially the AR constraints listed per packet.

The owner explicitly selected **D1-A: report listener exceptions and preserve the send result**. That choice is now recorded in the November decision log. Do not reopen it without new evidence. D2-D7 remain undecided; this directive does not select them.

No commits, pushes, tags, repository visibility changes, history rewrites, provider calls, real credentials, or outbound provider-support messages are authorized by these packets. T-07 remains owner-operated. Routine local code, tests, and documentation within the named packets can proceed.

## 2. Ownership and shared-tree coordination

| Role | Active packet | Exclusive writable paths |
|---|---|---|
| Engineer/agent A - distribution | T-08A | `.gitattributes`; `.docs/dev/implementation/T-08A-archive-exclusions.md` |
| Engineer/agent B - webhook | T-05 | `src/Http/Controllers/TextSmsCallbackController.php`; `src/Http/Middleware/VerifyTextSmsCallback.php`; `src/Contracts/SmsDeliveryReportProcessor.php` documentation; `tests/Feature/WebhookTest.php`; optional `tests/Feature/WebhookLogOnlyHygieneTest.php`; `docs/webhooks.md`; `.docs/dev/implementation/T-05-webhook-hygiene.md` |
| Engineer/agent C - gateway | T-01 | `src/Gateways/TextSmsGateway.php`; documentation in `src/Exceptions/SmsProviderException.php`; `tests/Feature/TextSmsGatewayTest.php`; new `tests/Feature/LifecycleEventIsolationTest.php`; `docs/events.md`; `.docs/dev/implementation/T-01-lifecycle-event-isolation.md` |
| Coordinator/integrator | Cross-packet integration | Task status, required `CHANGELOG.md` entries, explicit shared-file handoffs, integrated checks |
| Independent reviewer | Acceptance | Read-only review of the diff and evidence; no implementation edits during review |

These are assignment roles, not a claim that named engineers have accepted the work. Assign one implementer to each role. A single engineer follows T-08A -> T-05 -> T-01.

The agents share one working tree. Do not run repository-wide formatting while another writer has unfinished PHP changes. T-05 owns `WebhookTest.php`; T-01's throwing-listener HTTP test belongs in its dedicated lifecycle regression file, using the existing Testbench setup. No gateway edit is needed for T-05's sanitized-input contract below.

Only the gateway owner edits `TextSmsGateway.php`. Later T-02 -> T-03 -> T-04 -> T-13 -> T-10 follow that same ownership handoff. Require an explicit handoff for the service provider, configuration, Composer, README, CHANGELOG, and any shared test file before another packet edits it.

## 3. T-08A - Archive exclusions

**Priority:** P0. **Status:** ready to implement. **Depends on:** no pending owner choice. **Review constraints:** AR-08.

Implement these bounded changes:

- Add `export-ignore` for `/.docs`, `/.agents`, `/.claude`, and `/pint.json`.
- Remove the obsolete `/.php-cs-fixer.dist.php` exclusion.
- Keep existing distribution exclusions and executable package contents intact.

Acceptance:

1. `git check-attr export-ignore -- .docs .agents .claude pint.json` reports set for all four entries.
2. Archive inspection demonstrates exclusion of the intended internal directories and inclusion of runtime source/config/migrations/routes. Directory attribute rules do not require each descendant's `git check-attr` output to report set.
3. Because internal directories are currently untracked, a HEAD-only archive is insufficient proof. An isolated temporary Git tree with harmless sentinel files can provide preliminary synthetic evidence without committing or staging this repository. Label that evidence synthetic; retain exact-release-tree inspection as a release gate.
4. The task record contains the attribute output, archive method/listing, actual checks, and remaining T-08B scope. No permanent test suite is needed for this small metadata change.

Do not edit recipient references or infer consent, publish internal reviews, or change Git history. Archive exclusions do not provide privacy for tracked repository content. Keep T-08B open for D6.

## 4. T-05 - Webhook input and secret hygiene

**Priority:** P0. **Status:** ready to implement. **Depends on:** no pending owner choice. **Review constraints:** AR-06; preserve T-01/T-03/T-06 separation.

Required behavior:

1. Remove top-level `token` before invoking the processor. Query and body authentication metadata must not reach the row or the event payload through the built-in webhook.
2. Denial logs must contain no presented token on either the wrong/missing-token branch or the unconfigured-secret branch. Prefer a query-free URL or explicit redaction using existing request capabilities.
3. Validate supplied token types before string conversion. Malformed tokens return 401. Preserve 500 for an unconfigured expected secret and constant-time comparison for valid string tokens. Preserve header precedence.
4. Validate authenticated message-ID and status field shapes before processor string casts. Preserve `messageid`/`messageID`, `status`/`dlrstatus`, and legitimate integer/string IDs. Reject arrays, objects, booleans, float IDs, and empty/blank ID strings with deterministic 422. Integer `0` and string `"0"` pass shape validation and follow the ordinary lookup/unknown-ID path; do not use `empty()` to reject them. Keep absent/null status on the existing unknown-status path; do not invent a terminal state.
5. An authenticated invalid payload produces one warning with payload keys only, zero dispatch changes, and no delivery event. Preserve 200 for a well-formed unknown ID.
6. Make the public processor precondition explicit in its PHPDoc and webhook documentation: direct callers must remove authentication secrets before invocation. This task guarantees sanitization at built-in HTTP ingress; it does not promise arbitrary direct-call payload sanitization.

Permanent regressions must demonstrate:

- A matched query-token callback persists and emits a token-free payload. Capture the real event payload with a listener or a narrowly scoped event fake that preserves model hooks.
- Header authentication and alternate provider keys continue to work.
- Both denial branches omit the submitted token from all captured log context.
- Malformed token, ID, and status shapes return the required status without mutations/events or value-bearing validation logs.
- Invalid authenticated payload diagnostics contain keys only and exactly one warning.
- An HTTP callback bound to the actual `LogOnlySmsGateway` receives/logs a token-free payload. Its source file need not change because ingress sanitization owns this guarantee.

Use standard Laravel request and validation capabilities. Add no sanitization service, middleware hierarchy, or provider parser abstraction. Do not change throttle defaults, DLR precedence, event-isolation behavior, or retry policy in this packet.

Record the owner-operated secret-rotation and historical row/log follow-up from the original T-05 packet. Existing deployments and external/proxy logs are outside this implementation's verification.

## 5. T-01 - Lifecycle-event isolation

**Priority:** P0, critical. **Status:** ready to implement under confirmed D1-A. **Review constraints:** AR-01, AR-04, AR-07; incorporate the description-length portion of R37/T-20.

Start with focused reproductions using faked provider requests and real throwing listeners. Then make the smallest correction at the gateway boundary:

1. Separate provider I/O/classification, local persistence, and consumer event dispatch. No consumer event runs inside a provider-classification catch block.
2. Persist all classified bulk row outcomes before dispatching any bulk lifecycle event. Publish events in input order and isolate each event attempt so a failure for one row does not stop the remaining rows' events.
3. Use one private event helper based on Laravel `rescue(..., report: true)`. Listener exceptions are reported, never change persisted provider outcome, and never replace the returned success or original provider exception.
4. Provider rejection/transport paths attempt `SmsDispatchFailed` once per affected row after persistence. Preserve provider code, description, structured errors, and the original cause.
5. Apply the helper to DLR publication after its existing persistence step. Leave the D3 transition policy unchanged in this packet.
6. Limit persisted `response_description` to 255 characters on both accepted and failed writes, preserving valid multibyte text. Keep real database enforcement verification in the release gate.

### Local persistence-error contract

The following is the engineering contract for this packet; it does not change D1:

- A local persistence exception is not a provider rejection. Do not call compensating `markFailed()` after a provider-accepted response.
- Keep the existing exception surface: wrap the local recording failure in `SmsProviderException` with the original exception as `previous` and a message explicitly identifying local recording failure. When acceptance is known, say that TextSMS accepted externally and automatic resend is unsafe; retain the observed provider code where available.
- This preserves the existing `SmsChannel` catch boundary and avoids default queued-notification failure solely because an accepted send could not be recorded. Direct callers must also respect the ambiguity warning.
- Do not emit a lifecycle event for a result whose persistence did not complete. Do not issue another provider call or claim the row is terminal when storage could not record that state.
- Capture bounded recovery context for the attempt and known client/provider IDs without credentials, recipient, body, or full response. Document the broader local-recording-error meaning in the existing exception PHPDoc and task record.
- A storage/model-observer exception can occur before or after an SQL write. Test both relevant placements; the row may remain pending or already reflect acceptance. Do not overwrite either result with invented rejection.
- Apply the accepted-externally warning only when acceptance is known. A pre-provider insertion error must identify local preparation failure without claiming that a request was sent. Inspect actual persisted state independently after a post-write observer or refresh exception.
- If one bulk row cannot record its classified outcome, attempt the remaining rows without another provider call. After those persistence attempts, publish events only for rows whose finalization completed without exception, then throw the existing package exception identifying partial local recording failure and unsafe blanket resend. Preserve the first recording exception as its cause and bounded recovery context for every result not successfully finalized, including known provider acceptance/IDs. Do not turn the entire batch into provider rejection or conceal unrecorded accepted results.

Do not add an exception type, retry mechanism, outbox, or transactional redesign to satisfy this contract. If an injected failure proves the boundary needs a different design, present the exact failure and proposed adjustment before widening scope.

Permanent regressions must demonstrate:

1. Throwing accepted listener: returned row stays sent, provider ID and `sent_at` survive, `failed_at` is null, listener error is reported, and only one provider request occurs.
2. Throwing failed listener: failure event is attempted once and the provider exception retains code, description, and structured errors.
3. Accepted, mixed, global-rejection, and transport bulk outcomes: all writable outcomes are finalized before the first listener runs; a throwing listener does not prevent event attempts for later rows.
4. Throwing DLR listener: real HTTP callback returns 200 and the persisted report survives. Put this case in the dedicated lifecycle regression file to avoid the T-05 test-file collision.
5. Two ordered listeners on one event: the first throwing listener prevents the second from running. Document this best-effort limit; rescuing the overall event does not guarantee every listener or queue submission executes.
6. Accepted response followed by storage/observer failure: no compensating failure classification or second provider request; bounded recovery signal and original cause retained. Exercise the channel boundary to verify the existing catch handles the recording exception.
7. Long accepted and failed descriptions remain within 255 characters, including multibyte cases. Do not claim SQLite validates MySQL/PostgreSQL length enforcement.
8. A middle-row bulk recording failure does not prevent later rows' recording attempts. Successfully finalized rows retain their real outcomes and receive event attempts after persistence; incomplete rows receive no lifecycle event. The partial-recording exception retains the first cause, recovery context accounts for all incomplete rows, and only one provider request was made.

Exclude bulk input/response-correlation redesign, automatic retry policy, DLR precedence/concurrency redesign, morph changes, doubles, and serialization-trait changes. Those stay in their existing packets.

## 6. Owner track and next handoffs

| Packet | Work to prepare next | Gate before implementation |
|---|---|---|
| T-07 | Owner gathers consenting-recipient live contract observations and sanitized fixture shapes from the existing procedure | Owner operates live calls; engineers do not infer ULID echo, deduplication, balance, or callback guarantees |
| T-02 | Complete preflight/insert transaction/correlation cases, including duplicate provider keys and accepted responses without usable IDs | Accepted T-01 handoff; make unknown-outcome classification explicit before parser changes |
| T-03 | Exact compare-and-update algorithm, repeated terminal timestamp preservation, deterministic interleavings | T-02 handoff and D3 choice |
| T-04 | Operation-specific retry predicates, outbound-only credential guards, errno-specific tests | T-03 handoff and D2 choice |
| T-06 | Package-default middleware coverage and configurable throttle examples | T-05 handoff and D4 choice |
| T-09/T-12/T-13/T-14/T-15 | Prepare integration packets from the reviewed backlog | Respect D5 for T-12; finish T-13 doubles edits before T-14; reserve shared files explicitly |
| T-08B | Recipient/history and internal-document disclosure choices | D6; no history rewriting without explicit authorization |
| T-18 | Compatible framework/Testbench/Pest matrix plan | T-17 and D7; widen constraints only with verification |

Do not start deferred sender pools, failover, queued sender, scheduling, HMAC, or retry-command work in this wave.

## 7. Verification, review, and completion report

Reproduce the defect first with a focused test or an autoloaded temporary probe. Convert useful probes to permanent regressions and remove temporary artifacts. Do not leave expected failing tests in the handoff. Prevent stray HTTP requests; use synthetic credentials and recipients only.

Each implementer runs focused domain checks. After the writers finish and the tree is stable, the coordinator runs the repository gate:

```text
php vendor/bin/pest --no-coverage
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/pint --dirty
php vendor/bin/pint --test
composer validate --strict
git diff --check
```

Run the style fixer only when file ownership is quiescent. Check that temporary/new PHP files are formatted explicitly if the dirty-file selection does not include them. T-08A alone needs attribute/archive checks and whitespace review, not a new PHP regression suite. Reuse integrated gate evidence only if it covers the final stable tree; date every recorded result.

The independent reviewer must challenge side-effect ordering, hidden retries, missing rows, listener-chain behavior, malformed input, secret logging, bulk partial failure, and misleading diagnostics. Source review and test evidence are both required.

Each handoff includes exact paths changed, behavior implemented, regressions and commands actually run, independent findings and resolutions, remaining uncertainty, and `.docs/dev/implementation/<task-id>-<slug>.md`. The coordinator applies required CHANGELOG entries with a single writer and performs a final status/diff review. Passing tests alone do not mark T-07, T-08B, or release readiness complete.

**Issued status:** T-08A, T-05, and T-01 are ready for assignment and implementation under this directive. Runtime implementation and its completion evidence belong to the receiving engineers/agents and subsequent handoffs.
