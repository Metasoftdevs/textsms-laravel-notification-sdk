# T-01 — Review corrections (W1-01 to W1-04)

**Date:** 2026-11-02 (Africa/Nairobi)
**Work order:** `.docs/dev/reviews/codex/2026-11-02-first-wave-independent-review-and-next-actions.md` §4
**Decision in force:** D1-A (unchanged)
**Status:** corrections implemented locally; not committed. T-01 stays
"changes requested" until the independent re-review accepts source and
evidence.

**Final focused review, 2026-11-02:** the second correction is accepted;
all W1 findings are closed within scope and T-01 is **accepted**. See
[final acceptance](../reviews/codex/2026-11-02-t01-final-acceptance.md).
The earlier dispositions below are historical review snapshots.

**Independent re-review, 2026-11-02:** W1-02 and W1-04 are accepted; the
substantive W1-03 documentation correction is accepted with a small original
task-record synchronization remaining. W1-01 is only partially corrected:
`rejectionKind()` still treats arbitrary nonempty non-200 values as confirmed
rejection. T-01 remains **changes requested**. See the
[re-review and focused work order](../reviews/codex/2026-11-02-t01-corrections-independent-re-review.md).
The implementation summary and earlier verification below describe the
handoff submitted for that re-review.

## Summary

| Finding | Correction | Boundary changed |
|---|---|---|
| W1-02 (P1) Diagnostic failures escaped isolation | `publish()` now uses `rescue(event, fn ($e) => reportQuietly($e), report: false)`. `reportQuietly()` wraps `report()` in `rescue(..., report: false)`, so a failing exception handler is dropped. `recordingFailure()` builds the exception **first**, then logs inside `rescue(..., report: false)`. A logger failure is not sent back through the logger. | `publish()`, new `reportQuietly()`, `recordingFailure()` |
| W1-01 (P1) Unknown output became "rejected" recovery advice | New outcome kind `unknown_response` for unparseable, unmatched, and codeless output (and a stray top-level `200`). `rejectionKind()`: explicit rejection only when TextSMS returned an error code *(too broad — any non-empty non-200 value counted; narrowed to the documented send-rejection list in the second correction below)*. `acceptance()` maps kinds to `accepted` / `rejected` / `unknown`; transport and unexpected errors are `unknown`. Recovery messages and the new `provider_acceptance` log field derive from this certainty. | `classifySend()`, `classifyBulk()`, new `rejectionKind()`, `acceptance()`, `recoveryContext()`, `recordingFailure()` and two message builders |
| W1-04 (P2) Construction failures bypassed the preparation wrapper | The `try` in `preInsert()` now starts before `new SmsDispatch([...])`, covering attribute filling (JSON casting of `metadata`). | `preInsert()` |
| W1-03 (P2) Docs overstated guarantees | Bulk publication described as "recording attempted for every row; events for recorded rows only". Recording guarantees scoped to `send()` / `sendBulk()`. DLR save-veto gap stated as known and tracked for T-03. Reporter and logger failures stated as best effort. | `docs/events.md`, gateway class docblock, `SmsProviderException` PHPDoc, `CHANGELOG.md`, T-01 record |

No public signature, persisted status value, event payload, retry policy,
DLR transition, parser shape, or double changed. Unknown outcomes are still
recorded as `failed` and still fire `SmsDispatchFailed`, exactly as before.

## Certainty model (W1-01)

| Provider answer | Kind | `provider_acceptance` | Resend from duplication's point of view |
|---|---|---|---|
| `responses[]` row with code `200` | `accepted` | `accepted` | unsafe — it was sent |
| `responses[]` row or top-level body with a documented send-rejection code (`1001`, `1002`, `1003`, `1004`, `1006`, `1009`, `1010`, `4091`, `4092`) | `rejected` | `rejected` | safe |
| Any other code: corrupted (`"garbled"`), undocumented (`9999`), server-side error (`1005`, `1007`, `4090`), or another operation's code (`1008`, `4093`) | `unknown_response` | `unknown` | unsafe — may have been sent *(second correction)* |
| Unparseable body (HTML, empty, unknown keys) | `unknown_response` | `unknown` | unsafe — may have been sent |
| Bulk row with no matching `clientsmsid`, or a row with no code | `unknown_response` | `unknown` | unsafe |
| Top-level `response-code: 200` outside the accepted shape | `unknown_response` | `unknown` | unsafe |
| Transport failure (`ConnectionException` / `RequestException`) | `transport_error` | `unknown` | unsafe |
| Unexpected stage-1 error | `unexpected_error` | `unknown` | unsafe |

Recording-failure messages:

- **Single, accepted:** "TextSMS accepted dispatch {ulid} (provider message id {id}), but recording the result locally failed. The SMS was sent; resending it automatically is unsafe."
- **Single, unknown:** "TextSMS gave no usable outcome for dispatch {ulid}: {detail}. Recording the outcome locally also failed. TextSMS may have accepted the SMS; resending it automatically is unsafe."
- **Single, rejected:** "{provider rejection message}. Recording this outcome locally also failed for dispatch {ulid}."
- **Bulk:** "Recording the TextSMS bulk result locally failed for n of m dispatches; …" followed by the first matching clause:
  - accepted a, may have accepted u: "resending the batch as a whole is unsafe: TextSMS accepted a and may have accepted u of the m messages."
  - accepted only: "resending the batch as a whole is unsafe: TextSMS accepted a of the m messages."
  - unknown only: "resending the batch as a whole is unsafe: TextSMS gave no usable outcome and may have accepted u of the m messages."
  - whole batch explicitly rejected: "TextSMS had rejected the whole batch: {message}"
  - every row explicitly rejected: "TextSMS rejected every dispatch in the batch."

Known-acceptance wording is used only for `accepted`.

## Regressions (`tests/Feature/LifecycleEventIsolationTest.php`)

All were written first and failed against the uncorrected code for the
reviewer's reasons. 12 cases failed; the run showed raw `LogicException`
escapes, "rejected" wording for unknown output, and a raw
`JsonEncodingException`.

| Finding | Test | Asserts |
|---|---|---|
| W1-02 | `returns the sent row when the exception reporter itself fails` | Real handler with a throwing `reportable()` callback; row `sent`; 1 request |
| W1-02 | `keeps the provider exception when the exception reporter fails during a failure event` | `SmsProviderException` code `1004` and description kept; event attempted once; 1 request |
| W1-02 | `keeps publishing later bulk events when the exception reporter fails` | Attempts A, B, C; all `sent`; 3 rows returned; 1 request |
| W1-02 | `answers 200 for a recorded DLR when the exception reporter fails` | HTTP 200; row `delivered` |
| W1-02 | `throws the local-recording exception with its cause when the recovery logger fails` | Accepted message, code `200`, `previous` = storage failure; `SmsChannel` returns null instead of throwing; 1 request per send |
| W1-01 | `calls a batch resend unsafe when an unknown bulk outcome cannot be recorded` (unparseable; unmatched) | "2 of 2", "may have accepted", "unsafe"; no "rejected"; no known-acceptance claim; `providerCode` null; both `pending`; `provider_acceptance` unknown ×2; 1 request |
| W1-01 | `calls a resend unsafe when an unknown single-send outcome cannot be recorded` (unparseable: 1 request; transport: 2 requests, existing D2-pending retry) | Same wording rules; `pending`; `unknown` |
| W1-01 | existing `keeps the provider rejection details…` and `does not call a batch TextSMS rejected outright unsafe…` | Added `provider_acceptance` = `rejected` (and "no unsafe" for the single case) |
| W1-04 | `reports a local preparation failure without contacting TextSMS` (4 datasets) | Unencodable `NAN` metadata → `previous` is `JsonEncodingException`, 0 rows; `creating` throws → 0 rows; `creating` cancels → 0 rows; `created` throws → 1 row; always "no request was sent", never "accepted"; `Http::assertNothingSent()` |

The W1-02 tests restore the real exception handler from the
`Exceptions::fake()` wrapper (`Exceptions::getFacadeRoot()->handler()`,
then `Exceptions::swap()`). Earlier lifecycle tests used only the fake, so
they never exercised a failing real reporter.

## Verification (2026-11-02, ≈ 00:35 EAT, stable tree)

| Command | Result |
|---|---|
| Focused: lifecycle, gateway, channel, webhook files | 83 passed (486 assertions) |
| `php vendor/bin/pint --dirty` | passed |
| `php vendor/bin/pint --test tests/Feature/LifecycleEventIsolationTest.php` | passed |
| `php vendor/bin/pint --test` | passed |
| `php vendor/bin/pest --no-coverage` ×3 | 106 passed (509 assertions) at seeds 1793568947, 1793568955, 1793568963 |
| `php vendor/bin/phpstan analyse --no-progress` | No errors (level 8) |
| `composer validate --strict` | valid |
| `git diff --check` | clean |
| Trailing whitespace in the changed untracked files | 0 |

## Remaining limitations and carryovers

- **Diagnostics are best effort.** If both the exception handler and the
  logger are broken, a listener failure or a recording failure leaves no
  trace beyond the thrown exception (recording) or nothing at all
  (listener). The send-result contract is unaffected. This is documented in
  `docs/events.md`.
- **Pre-existing provider-exception wording (T-02 carryover).** When
  recording succeeds, the normal provider exception for unparseable output
  still reads "TextSMS rejected dispatch (): Unparseable…" or "TextSMS bulk
  rejected (unknown): …". That text predates the wave and was left for
  T-02, which must make unknown acceptance distinguishable from explicit
  rejection in the parser and its exceptions.
- **Unknown rows are persisted as `failed`.** The state vocabulary is
  unchanged by design. Their `metadata.provider_diagnostics.parse_source`
  marks unparseable output; unmatched rows get no diagnostics block yet
  (T-02 owns the `unmatched` diagnostic).
- **T-03 carryover (W1-03).** A delivery-report update cancelled by a model
  listener is still acknowledged with 200 and still fires
  `SmsDeliveryReported`. T-03's acceptance criteria must require confirmed
  persistence before publication and define the failure response.
- **Other gateway log calls** (`recordDeliveryReport()` warning/info) are
  not wrapped. A broken logger there still surfaces as a webhook error,
  which is outside this packet.

## Reviewer focus

1. `publish()` / `reportQuietly()`: listener and reporter failures are both
   contained; nothing else in the event path changed.
2. `rejectionKind()`: treating a stray top-level `200` as unknown, not as
   rejection.
3. The bulk message clause order when counts mix accepted, unknown, and
   rejected outcomes.

---

## Second correction — W1-01 completion (2026-11-02)

**Work order:** `.docs/dev/reviews/codex/2026-11-02-t01-corrections-independent-re-review.md` §2
**Defect:** `rejectionKind()` returned `rejected` for any non-empty value
other than `200`. Corrupted or undocumented codes (`"garbled"`, `9999`)
therefore produced `provider_acceptance = rejected` and confirmed-rejection
wording with no unsafe-resend warning.

### Correction

`rejectionKind()` now checks the code against a private constant,
`TextSmsGateway::SEND_REJECTION_CODES`. Its docblock cites the provider PDF
code table (p. 4) and explains each exclusion. Every other value is
`unknown_response`. No other method, persisted value, event, exception
type, or retry behaviour changed.

| Code(s) | Provider meaning | Classification | Why |
|---|---|---|---|
| 1001, 1002, 1003, 1004, 1006, 1009, 1010, 4091, 4092 | invalid sender / network not allowed / invalid mobile (and validation) / low credits / invalid credentials / unsupported data or request type / no partner id / no API key | confirmed rejection | The request was refused; nothing was sent. |
| 1005, 1007, 4090 | "Failed. System error" / "Internal Error. Try again after 5 minutes" | unknown | A server-side failure does not prove the message was not dispatched. |
| 1008, 4093 | "No Delivery Report" / "Details Not Found" | unknown | They describe other operations, not a send refusal. |
| anything else (`"garbled"`, `9999`, …) | — | unknown | Unrecognised value. |

This is an engineering classification, not an owner decision. Treating
server-side errors as unknown is the conservative choice: their recovery
messages warn against automatic resend instead of declaring nothing was
sent. T-07's live evidence may justify promoting a code later.

### Regressions (written first; 12 failed before the fix)

| Test | Cases |
|---|---|
| `calls a batch resend unsafe when an unknown bulk outcome cannot be recorded` | Added: corrupted top-level code, undocumented top-level code, server-side error top-level code (`4090`), corrupted row codes, undocumented row codes, non-send row codes (`1008`). Top-level cases keep the **observed** code on the exception (`garbled`, `9999`, `4090`), but acceptance is `unknown`. |
| `calls a resend unsafe when an unknown single-send outcome cannot be recorded` | Added: row without a code, stray top-level `200`, corrupted row code, undocumented row code, corrupted top-level code, undocumented top-level code, server-side error row code (`1005`). |
| `treats every documented send-rejection code as a confirmed rejection` (new) | One case per listed code: rejection wording, no "unsafe", observed code kept, acceptance `rejected`. |
| `decides batch resend safety from every outcome, including rows that were recorded` (new) | A accepted and recorded, B `garbled` and recorded, C `1006` and unrecorded. Message: "TextSMS accepted 1 and may have accepted 1 of the 3 messages"; unrecorded = [C] with acceptance `rejected`. Shows that whole-call certainty, not just the unrecorded rows, decides blanket-resend safety. |

Red run: 12 failed — all six new bulk datasets, five of the seven new
single-send datasets (corrupted and undocumented row and top-level codes,
and `1005`), and the mixed-count test. The codeless row, the stray
top-level `200`, and all nine documented codes already passed, and now
serve as guards.

### Documentation synchronised

- Gateway class docblock and `acceptance()` docblock: "documented
  send-rejection code".
- `SmsProviderException` PHPDoc: an unrecognised code counts as no usable
  outcome.
- `docs/events.md`: lists the rejection codes and names server-side errors
  as possibly sent.
- Original T-01 record: the three stale `rescue(..., report: true)`
  descriptions now describe `publish()` with `report: false` and the
  separately contained `reportQuietly()` helper. Historical test dates are
  unchanged.
- `CHANGELOG.md`: notes that only documented send-rejection codes count as
  rejections.

### Verification (2026-11-02)

Recorded in the integration record's "W1-01 completion" section.
