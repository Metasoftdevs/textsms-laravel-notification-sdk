# T-05 — Webhook input and secret hygiene

**Date:** 2026-11-01 (Africa/Nairobi)
**Baseline:** `main` @ `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca`
**Directive:** `.docs/dev/directives/2026-11-01-first-wave-execution-directive.md` §4
**Review constraint:** AR-06
**Findings closed:** R17 (token stored in rows/events), R19 (token in denial logs), R20 (silent 422), plus the AR-06 type defects.
**Status:** implemented locally; not committed. Integrated gate results: see the end of this record.

## Intent and mental model

The DLR webhook is the only inbound HTTP surface. It must keep the shared
secret at the HTTP boundary and must hand the processor only payloads whose
key fields have safe shapes.

```text
request ──► VerifyTextSmsCallback ──► TextSmsCallbackController ──► processor
             │ 500 secret unset       │ strip `token`
             │ 401 token not a string │ 422 bad id/status shape (keys-only warning)
             │ 401 mismatch           │ otherwise: recordDeliveryReport(sanitised)
             │ log: URL w/o query,    │
             │      query keys only   │
```

**Guarantee boundary.** Sanitisation is guaranteed at the built-in HTTP
ingress. A direct call to `SmsDeliveryReportProcessor::recordDeliveryReport()`
is not sanitised; the interface PHPDoc and `docs/webhooks.md` now state that
direct callers must strip secrets first. `TextSmsGateway` and
`LogOnlySmsGateway` were not changed.

## Changes

| Path | Change |
|---|---|
| `src/Http/Middleware/VerifyTextSmsCallback.php` | Header-first token lookup kept (`header ?? query`). The value is type-checked before comparison: a non-string (e.g. `?token[]=…`) returns 401 instead of raising "Array to string conversion" (500). Denial log uses `$request->url()` (no query) plus `query_keys`; the `fullUrl()` that carried the token is gone. 500-for-unset-secret and `hash_equals()` are unchanged. |
| `src/Http/Controllers/TextSmsCallbackController.php` | Injects `LoggerInterface`. Passes `$request->except('token')` to the processor. Validates the effective message id (`messageid` ?? `messageID`): integer or non-blank string. Validates the effective status (`status` ?? `dlrstatus`): absent, string, int, or float. Failures return 422 with one warning `{reason, payload_keys}`. `empty()` is no longer used, so `0`/`"0"` pass. |
| `src/Contracts/SmsDeliveryReportProcessor.php` | PHPDoc: input precondition (no secrets; direct callers sanitise). |
| `docs/webhooks.md` | Auth model, payload handling, response contract, direct-call precondition, upgrade/rotation follow-up. Throttle text untouched (T-06). |
| `tests/Feature/WebhookTest.php` | 13 new cases (22 with datasets). |

### Design notes

- **Standard Laravel capabilities only:** `Request::except()`, `Request::url()`,
  `Request::query()`, the PSR-3 logger. No sanitisation service, middleware
  hierarchy, or parser abstraction was added.
- **Why not `Validator`:** Laravel's `integer` rule accepts booleans and
  numeric strings, and no built-in rule expresses "integer or non-blank
  string, with `messageid` taking precedence over `messageID`". A ten-line
  check at the controller boundary is the proportional fix.
- **Field precedence** mirrors the processor's `??` chains, so the controller
  validates exactly the value the processor will cast.
- **Blank vs invalid:** a missing, null, or blank id answers
  `Missing messageid in payload`; a wrongly typed id answers
  `Invalid messageid in payload`. Both are 422.
- **Global middleware interplay:** in a default Laravel app, `TrimStrings`
  and `ConvertEmptyStringsToNull` run first, so a blank id usually arrives
  as `null`. The controller handles both forms.

## Edge-case matrix

| Input (authenticated unless stated) | Status | Row change | Event | Log |
|---|---|---|---|---|
| `?token=<secret>` + valid matched payload | 200 | updated; `dlr_payload` has no `token` | 1, payload has no `token` | none |
| header auth + body `token` key | 200 | `dlr_payload` has no `token` | payload has no `token` | none |
| `?token[]=…` | 401 | none | none | denial; no value |
| header wrong, query right | 401 (header wins) | none | none | denial |
| wrong token | 401 | none | none | denial; URL without query; `query_keys` |
| secret unset | 500 | none | none | denial; no value |
| id missing / null / blank | 422 | none | none | 1 warning, keys only |
| id array / object / bool / float | 422 | none | none | 1 warning, keys only |
| status array / object / bool | 422 | none | none | 1 warning, keys only |
| id `0` or `"0"` | 200 | lookup → unknown id | none | info "unknown messageid" |
| integer id `78726470` | 200 | matched and updated | 1 | none |
| status absent | 200 | payload stored, status unchanged | 1 (`unknown`) | none |
| `messageID` + `dlrstatus` | 200 | updated | 1 | none |
| LogOnly processor bound | 200 | n/a | n/a | "DLR ignored" with token-free payload |

## Verification (2026-11-01)

**Reproduction first.** The new cases were written before the fix and run
against the unchanged controller and middleware: 17 failed for the expected
reasons. Notably, array `messageid` and array `status` produced HTTP 500 from
`ErrorException: Array to string conversion` at `TextSmsGateway.php:297` and
`:316`. This confirms at runtime the AR-06 defects that were previously only
identified statically.

**After the fix:**

| Command | Result |
|---|---|
| `vendor/bin/pest --no-coverage tests/Feature/WebhookTest.php` | 30 passed (145 assertions) |
| `vendor/bin/phpstan analyse --no-progress` | No errors |
| `vendor/bin/pint --test` on the four changed PHP files | Passed after Pint fixed one `phpdoc_align` issue in the contract file |

Log capture uses Laravel's `MessageLogged` event, so assertions cover every
entry written through the application logger. Event capture uses
`Event::listen()`, which leaves Eloquent model hooks (the ULID generator)
running.

## Remaining risks and owner follow-up

1. **Rotate secrets (owner).** Before this change, every successful DLR stored
   the `?token=` value in `dlr_payload`, and every denied callback logged it.
   Rotate `TEXTSMS_DLR_SECRET` wherever callbacks were received, and decide
   whether to scrub existing rows and logs. Reverse-proxy and access logs are
   outside the package's control. No deployment was inspected or changed.
2. **QPIMS lineage (owner).** QPIMS runs the same controller pattern. Whether
   it forwards `$request->all()` was not verified (outside this repository).
3. **Direct processor calls** remain the caller's responsibility (documented).
4. **Query-string secrets** still travel in URLs and therefore in
   intermediary logs. The header form avoids this; the docs now say so.

## Integrated gate

Recorded in `.docs/dev/implementation/2026-11-01-first-wave-integration.md`.
