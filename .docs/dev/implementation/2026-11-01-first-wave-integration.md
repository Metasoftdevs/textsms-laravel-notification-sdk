# First wave — integration record

**Date:** 2026-11-01 (Africa/Nairobi)
**Review status updated:** 2026-11-02 (Africa/Nairobi)
**Baseline:** `main` @ `f6b76272d2d48e34b6e64956dfadcf7034d1f5ca` (no commits made)
**Directive:** `.docs/dev/directives/2026-11-01-first-wave-execution-directive.md`
**Execution mode:** one engineer, in the directive's single-engineer order
T-08A → T-05 → T-01. The same engineer then acted as coordinator (CHANGELOG,
integrated gate, status review). At the original 2026-11-01 handoff, no
independent reviewer had reviewed this work. The subsequent review is linked
in the packet status table below.

## Packet status

| Packet | Status | Task record |
|---|---|---|
| T-08A Archive exclusions | Accepted within preliminary archive scope by independent review, 2026-11-02; exact release archive remains open | `T-08A-archive-exclusions.md` |
| T-05 Webhook hygiene | Accepted within built-in HTTP ingress scope by independent review, 2026-11-02 | `T-05-webhook-hygiene.md` |
| T-01 Lifecycle-event isolation | Accepted after the second W1-01 correction and focused review, 2026-11-02 | [Final acceptance](../reviews/codex/2026-11-02-t01-final-acceptance.md) |
| Independent review (directive §7) | First-wave engineering packets accepted within their defined scopes, 2026-11-02; release gates remain open | [Final acceptance](../reviews/codex/2026-11-02-t01-final-acceptance.md) |
| T-08B, T-07, D2–D7 | Untouched; still open | — |

"Implemented" is not "accepted". The packet dispositions above were updated
after the 2026-11-02 independent source/evidence review. The implementation
chronology and gate results below remain historical evidence from 2026-11-01.
T-01's correction packet and subsequent review completed before its T-02
implementation handoff. Later T-02/T-09/T-15/T-20 implementation and gates
are recorded in [the current execution status](2026-11-02-execution-status.md).

## Paths changed

| Path | Packet |
|---|---|
| `.gitattributes` | T-08A |
| `src/Http/Controllers/TextSmsCallbackController.php` | T-05 |
| `src/Http/Middleware/VerifyTextSmsCallback.php` | T-05 |
| `src/Contracts/SmsDeliveryReportProcessor.php` (PHPDoc) | T-05 |
| `tests/Feature/WebhookTest.php` | T-05 |
| `docs/webhooks.md` | T-05 |
| `src/Gateways/TextSmsGateway.php` | T-01 |
| `src/Exceptions/SmsProviderException.php` (PHPDoc) | T-01 |
| `tests/Feature/LifecycleEventIsolationTest.php` (new) | T-01 |
| `docs/events.md` | T-01 |
| `CHANGELOG.md` | Coordinator |
| `.docs/dev/implementation/*.md` (4 new records) | All |

Every path is inside the directive's ownership table. No service provider,
config, Composer, README, routes, migration, double, or event-class file
changed. No commits, pushes, tags, visibility changes, history rewrites,
provider calls, or real credentials.

## Integrated gate (stable tree, 2026-11-01 ≈ 23:56 EAT)

Run after all three writers finished, in the directive's order:

| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty` | passed (no changes needed) |
| `php vendor/bin/pint --test tests/Feature/LifecycleEventIsolationTest.php` | passed (new untracked file checked explicitly) |
| `php vendor/bin/pint --test` | passed |
| `php vendor/bin/pest --no-coverage` | **94 passed, 424 assertions**, seed 1793566623 |
| `php vendor/bin/phpstan analyse --no-progress` | No errors (level 8) |
| `composer validate --strict` | valid |
| `git diff --check` | clean |
| Trailing-whitespace scan of the new untracked files | 0 lines |
| Re-runs of `pest` under new random orders | 94 passed at seeds 1793566655 and 1793566662 |

Baseline before this wave: 52 tests, 131 assertions. Added: 22 webhook
cases and 20 lifecycle cases (datasets expanded).

## Remaining uncertainty

- Independent review found T-01 recovery/isolation and documentation gaps;
  see the linked 2026-11-02 review for reproductions and corrective work.
- Live provider behaviour (T-07) is unchanged and unverified.
- MySQL/PostgreSQL enforcement of the 255-character column is untested
  (SQLite only); a release gate.
- Owner follow-up from T-05: rotate `TEXTSMS_DLR_SECRET` where callbacks
  were received, and decide on scrubbing historical rows and logs.

## Next handoffs (from directive §6)

- **T-02** can start from the accepted T-01 handoff. It owns atomic bulk
  preflight and must make unknown-outcome classification explicit before
  changing the parser.
- **T-06** needs D4. **T-03** needs D3. **T-04** needs D2. **T-08B** needs D6.

## T-01 correction packet (2026-11-02)

The gateway implementer submitted corrections for the four work-order
findings (W1-01 to W1-04). Details, regressions, and the intended certainty
model are in `T-01-review-corrections.md`. The subsequent re-review below
records what was accepted and what remains open.

Stable-tree gate on 2026-11-02 (≈ 00:35 EAT): `pint --dirty`, `pint --test`
(including the untracked lifecycle test file explicitly) passed;
`pest --no-coverage` passed with **106 tests, 509 assertions** at seeds
1793568947, 1793568955, and 1793568963; PHPStan level 8 reported no errors;
`composer validate --strict` valid; `git diff --check` clean.

Paths changed by the correction packet: `src/Gateways/TextSmsGateway.php`,
`src/Exceptions/SmsProviderException.php` (PHPDoc), `docs/events.md`,
`CHANGELOG.md`, `tests/Feature/LifecycleEventIsolationTest.php`,
`.docs/dev/implementation/T-01-lifecycle-event-isolation.md`, and the new
`.docs/dev/implementation/T-01-review-corrections.md`.

Carryovers recorded for later packets: T-02 must reword the pre-existing
"rejected" provider exceptions for unparseable output; T-03 must require
confirmed DLR persistence before publication (cancelled-save case).

## T-01 correction re-review (2026-11-02)

[Independent re-review and remaining work](../reviews/codex/2026-11-02-t01-corrections-independent-re-review.md):
W1-02 and W1-04 accepted; substantive W1-03 public documentation accepted.
W1-01 remains P1 because unrecognized codes such as `garbled` and `9999`
still produce confirmed-rejection recovery advice. T-01 stays **changes
requested**, and T-02 implementation waits for its accepted handoff.

The re-review independently reran the gate: 106 tests, 509 assertions,
seed 1793570007; PHPStan, Pint, Composer validation, and whitespace checks
passed. Ten in-memory probes confirmed corrected boundaries and reproduced
the remaining rejection-recognition defect. See the linked record for
the bounded correction and evidence limits.

## W1-01 completion (2026-11-02)

The gateway implementer applied the focused correction from the re-review:
confirmed rejection now requires a documented send-rejection code
(`TextSmsGateway::SEND_REJECTION_CODES`); every other value is an unknown
outcome. The original T-01 record's stale `rescue(..., report: true)`
descriptions were synchronised. Details and the code table are in
`T-01-review-corrections.md` ("Second correction").

Regressions were written first: 12 failed against the uncorrected predicate.

Stable-tree gate on 2026-11-02 (≈ 02:06 EAT): `pint --dirty`, `pint --test`
(including the untracked lifecycle test file explicitly) passed;
`pest --no-coverage` passed with **129 tests, 669 assertions** at seeds
1793574427, 1793574436, and 1793574445; PHPStan level 8 reported no errors;
`composer validate --strict` valid; `git diff --check` clean; zero
trailing-whitespace lines in the changed untracked files.

Paths changed: `src/Gateways/TextSmsGateway.php`,
`src/Exceptions/SmsProviderException.php` (PHPDoc), `docs/events.md`,
`CHANGELOG.md`, `tests/Feature/LifecycleEventIsolationTest.php`,
`.docs/dev/implementation/T-01-lifecycle-event-isolation.md`,
`.docs/dev/implementation/T-01-review-corrections.md`, and this record.

T-01 stays **changes requested** until the focused independent review
accepts W1-01. T-02 implementation still waits for that accepted handoff.
