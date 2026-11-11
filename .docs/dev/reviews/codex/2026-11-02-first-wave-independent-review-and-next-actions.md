# First-Wave Independent Review and Next Actions

**Review date:** 2026-11-02, Africa/Nairobi\
**Baseline:** `main` at `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`, plus the uncommitted first-wave changes\
**Reviewers:** Codex and three independent read-only review agents\
**Scope:** source, regression tests, documentation, and task evidence for T-08A, T-05, T-01. Runtime implementation was not edited during this review. No commits, provider calls, credentials, connector operations, or publication.

## 1. Verdict

| Packet | Independent disposition | Practical consequence |
|---|---|---|
| T-08A | Accepted within its assigned preliminary archive scope | Keep exact-release archive verification and D6/T-08B open. |
| T-05 | Accepted within the built-in webhook ingress scope | Keep direct-caller sanitization preconditions and deployment secret rotation explicit. |
| T-01 | Changes requested | Resolve the findings below and repeat focused review before the T-02 implementation handoff. |

The full first wave is not accepted as a unit. The 94-test gate is green, but focused adversarial probes expose two high-priority gaps in the new recovery/isolation contract. There are also two smaller contract/documentation corrections.

The three-stage outbound flow, input-order publication, continuation after individual bulk recording failures, cancelled outbound-save checks, preserved storage causes, and multibyte description cap match the directive. No unnecessary public abstraction or exception hierarchy was introduced.

Review inputs:

- [First-wave directive](../../directives/2026-11-01-first-wave-execution-directive.md).
- [Integration record](../../implementation/2026-11-01-first-wave-integration.md) and the three packet records beside it.
- [Context and adversarial plan review](2026-11-01-contextualization-and-adversarial-plan-review.md).
- Current tracked diff, the untracked lifecycle test file, relevant existing tests, and installed framework behavior.

## 2. Findings, ordered by priority

### W1-01 - P1: Unknown provider outcomes become confirmed-rejection recovery advice

**Source:** `src/Gateways/TextSmsGateway.php:327`, `:359`, `:383`, `:502`, `:616`.

The new classification helpers label an unparseable response or an absent client response as `kind = rejected`. The new recovery path interprets that kind as evidence that resending the batch is not unsafe. When local recording also fails, its exception can state that TextSMS rejected the whole batch despite there being no usable provider outcome.

**Reproduced:** fake an HTML response with HTTP 200 for a two-item bulk request, then make the model's `updating` listener return false. One provider request occurs, both rows remain pending, and the exception contains:

```text
TextSMS had rejected the whole batch: TextSMS bulk rejected (unknown): Unparseable TextSMS bulk response (HTTP 200)
```

`providerCode` is null. This is unknown acceptance, so the confirmed-rejection guidance can cause an operator to resend messages already accepted externally.

**Correction:** distinguish explicit provider rejection from unknown post-send output when computing recovery certainty. Unknown/unmatched/unparseable/transport outcomes must prohibit automatic or blanket resend. Preserve the explicit documented-rejection case. This does not require completing T-02's parser redesign or changing the database state vocabulary.

**Required regressions:** unparseable response plus recording failure; missing client correlation plus recording failure; single-send unknown response plus recording failure; explicit 1003 rejection plus recording failure. Assert exception wording, diagnostic certainty, stored state, and request counts. Unknown results must say acceptance is possible and automatic resend unsafe, without claiming known acceptance. The old parser ambiguity predates this wave; the stronger new recovery claim is the change that must be corrected here.

### W1-02 - P1: Diagnostic failures bypass event and recording-error isolation

**Source:** `TextSmsGateway.php:545`, `:585`; installed Laravel `rescue()` reporting and exception-handler callbacks.

Two diagnostic operations are outside a protective boundary:

1. `rescue(..., report: true)` catches the lifecycle listener error, but an exception raised by `report()` itself escapes. With a throwing real reporting callback, a successful send returns a raw reporting exception even though its row is sent. Bulk event attempts can stop, and the DLR response can become 500.
2. `recordingFailure()` calls the recovery logger before constructing its required `SmsProviderException`. A throwing logger replaces the package exception, discards the storage cause, and bypasses `SmsChannel`'s catch boundary after provider acceptance.

**Reproduced:** an accepted-listener exception plus a real `reportable()` callback that throws yields raw `LogicException`, with the row sent. Separately, an accepted response plus an updating exception and an injected error logger that throws yields raw `LogicException`, no previous cause, and a pending row. Each outbound probe made one provider request.

**Correction:** contain failures in the diagnostic operation itself. Keep the successful return or original provider exception authoritative. Construct and preserve the intended recording exception regardless of whether its recovery log succeeds. Attempt reporting/logging best effort; avoid recursively sending logger errors back through the same broken logger. No fallback persistence service or new exception type is needed.

**Required regressions:** real reporting callback failure for accepted, failed, bulk, and DLR publication; error-logger failure during accepted local-recording failure. Assert preserved return/exception fields and cause, later bulk event attempts, appropriate DLR success behavior, and no extra provider calls. Existing lifecycle tests install `Exceptions::fake()`, so they do not exercise a failing real reporter.

If the diagnostic sink is unavailable, a log cannot be guaranteed. Documentation must state that limitation without weakening the send-result contract.

### W1-03 - P2: Event documentation claims broader persistence guarantees than the code implements

**Source:** `docs/events.md:47`, `:71`; `TextSmsGateway.php:282`.

The new documentation says every bulk outcome is recorded before publication. The actual intended partial-failure contract is that recording is attempted for every row, then events publish for successfully finalized rows only.

It also says all cancelled saves suppress events and produce the new recording wrapper/log. That new protection is implemented for outbound sends. The unchanged DLR path ignores `update()` returning false.

**Reproduced:** create a sent dispatch, register an updating listener returning false, and post an authenticated delivered callback. The response is 200; storage remains sent; one event contains `deliveryStatus = delivered` and `dispatch.status = sent`.

**Correction now:** narrow the new recording-error guarantees to `send()`/`sendBulk()` and describe partial bulk publication accurately. Record the DLR save-veto limitation explicitly.

**T-03 carryover:** require confirmed DLR persistence before publication; a vetoed update must not emit a successful transition or acknowledge it as recorded. Define its failure response explicitly in that packet rather than assuming a particular status here. Add that case to the transition/persistence acceptance criteria alongside concurrent/repeated reports. This runtime defect predates the wave, and the first-wave directive explicitly preserved existing DLR persistence; it is not a new T-05 defect or a reason to redesign D3 here.

### W1-04 - P2: The new preparation wrapper excludes model construction failures

**Source:** `TextSmsGateway.php:705`, `:721`; `src/Exceptions/SmsProviderException.php:14`.

The preparation try/catch begins at `save()`, after model construction and attribute filling. JSON metadata can fail serialization during construction and escape as a raw exception, bypassing the documented preparation wrapper and channel catch.

**Reproduced:** send an `SmsMessage` with synthetic metadata `['value' => NAN]`. Eloquent throws raw `Illuminate\Database\Eloquent\JsonEncodingException`; zero rows and zero provider requests occur.

**Correction:** include model construction/initialization in the existing preparation error boundary and retain the original cause and no-request wording. No new exception type is needed. Alternatively, an explicitly documented bad-input rejection can use the existing dispatch exception contract, but do not leave the newly advertised preparation boundary incomplete.

**Required regression:** constructor-time JSON encoding failure, plus retained save-cancellation and pre/post-write observer cases. Assert the existing package exception surface and zero provider requests. This raw behavior existed before the wave; the new local-preparation guarantee is incomplete rather than a newly introduced encoding regression.

## 3. Fresh verification evidence

All checks below ran on 2026-11-02 against the current implementation. No source fixer ran during review.

| Command / check | Result |
|---|---|
| `php vendor/bin/pest --no-coverage --compact` | 94 passed, 424 assertions; seed 1793567292; reported duration 8.28 s |
| `php vendor/bin/phpstan analyse --no-progress` | No errors, configured level 8 |
| `php vendor/bin/pint --test` | Passed |
| `composer validate --strict` | Valid |
| `git diff --check` | Clean |
| `git check-attr export-ignore -- .docs .agents .claude pint.json .php-cs-fixer.dist.php` | Four intended entries set; obsolete entry unspecified |
| Tracked-content archive inspection | Runtime source/config/migration/routes and required root files present; Pint and existing development directories excluded |
| Five focused in-memory probes | All completed; observed defects recorded above; script exited 0 |

The archive check used `git archive --worktree-attributes --format=tar HEAD`, captured and inspected as binary data through Python. It checked tracked HEAD content with current attribute rules, not the uncommitted implementation content or untracked internal directories. T-08A's earlier synthetic sentinel proof was reviewed as preliminary evidence and was not represented as an exact release artifact.

The five probe cases were unknown bulk output plus save veto, accepted local-recording failure plus broken logger, accepted lifecycle listener plus broken reporter, DLR save veto, and constructor-time metadata encoding failure. They ran through standard input with autoloaded package/framework classes and Testbench's in-memory database; PHPUnit's configuration registry was initialized. HTTP was faked and stray requests prohibited. No probe file or live request was created.

The implementer's earlier three random seeds and red-before-green chronology remain dated implementation evidence. The current review independently confirms the final gate and the listed counterexamples; it cannot establish the historical sequence of edits from today's uncommitted tree.

The existing PHP startup Imagick version warning did not prevent these checks. No dependency refresh/audit, real-database enforcement smoke, provider contract capture, deployment inspection, or remote publication check was performed.

## 4. Draft corrective work order

Return the following as a focused **T-01 review-correction packet** to one gateway implementer:

| Order | Deliverable | Writable boundary |
|---|---|---|
| 1 | W1-02: isolate reporter and recovery-logger failures; preserve authoritative result/cause | Existing gateway publication and recording-error helpers; lifecycle regressions |
| 2 | W1-01: make recovery certainty conservative for unknown/unmatched results | Existing outcome classification/recovery predicates; focused response-and-recording regressions |
| 3 | W1-04: complete the local preparation boundary | Existing `preInsert()` path and regression |
| 4 | W1-03: align documentation and task claims | `docs/events.md`, exception PHPDoc where affected, required CHANGELOG corrections, T-01 task record |

Keep one writer on `TextSmsGateway.php`. No broad refactor, retry-policy change, DLR precedence change, event serialization change, morph fix, new public contract, or provider call belongs in this correction packet. Use the existing lifecycle test file and proportional implementation documentation, for example `.docs/dev/implementation/T-01-review-corrections.md`.

The implementer should first turn these probe inputs into permanent regressions that fail for the observed behavior. After correction, rerun focused tests, the full stable-tree gate, and independent adversarial review. Keep T-01 changes-requested until both source and evidence are accepted. No commit is authorized by this review.

## 5. Next implementation and owner actions

1. **Complete and accept the T-01 corrections first.** T-08A and T-05 do not need unrelated reimplementation. Keep the first-wave integration record linked to this review and preserve historical gate dates.
2. **Prepare T-02 now; implement after the accepted T-01 handoff.** Its packet must cover whole-batch preflight, indexed input errors, unique resolved client references, atomic insertion on the configured model connection, zero HTTP on preparation failure, input-order results, and reordered/missing/duplicate/unexpected/malformed provider rows. Define code-200-without-provider-ID and conflicting HTTP/body outcomes before parser changes. Unknown acceptance must remain distinguishable from explicit rejection. T-02 can reuse the corrected outcome/finalization boundary without reopening D1.
3. **Obtain the next behavior decisions as their packets approach:** D3 for T-03 (recommended delivered-final policy); D2 for T-04 (proven pre-send errors only, or no automatic send retries); D4 for T-06 (recommended no default shared-IP throttle). D1-A remains approved; this review does not select D2-D7. T-06 can run alongside the gateway lane once D4 is selected.
4. **Keep T-07 moving on the owner track.** Exact sanitized fixtures must establish ULID echo, provider deduplication, balance output, pull-DLR shape, and actual push behavior before those guarantees are claimed. Engineers may prepare fixture schemas without live calls or support messages.
5. **Operational follow-up:** the owner rotates `TEXTSMS_DLR_SECRET` in affected deployments after rollout and decides historical row/log handling. No deployment or historical purge was performed by this review. D6 governs T-08B's recipient/history and public-disclosure decisions.
6. **Retain release gates:** exact release archive, supported framework/dependency matrices, current audit, fresh-app smoke, and real-database column enforcement. A green SQLite gate and accepted first-wave packets do not establish release readiness.

Unrelated connector authorization is not a dependency for this local review or these engineering packets.
