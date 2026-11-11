# T-19 bounded public documentation corrections

**Date:** 2026-11-11, Africa/Nairobi. **Implementer:** documentation agent.
**Status:** implemented; documentation and receipt-example comments only.
Focused independent documentation review accepted after two wording corrections.

## Approved boundary and plan

Update `examples/ReceiptNotification.php`, `docs/upgrade-guide.md`,
`docs/queueing.md`, `CONTRIBUTING.md` and the publication checklist to match
the approved passive callback contract. Remove the unsafe optional queued
sender/deleting-placeholder recipe and use Laravel notification queueing.
Correct release/upgrade claims, migration publication expectations and
conditional framework/database verification gates. Preserve other agents'
source, metadata, README, CHANGELOG and task-record ownership.

Verify the edited documentation against the local implementation and queue
framework source; check local links, PHP syntax for the comment-only example
edit and whitespace for owned files. No behavioral tests are needed for this
documentation-only packet. Record only checks actually performed. No commit,
push, provider request, dependency change or release operation is included.

## Actual changes

| File | Correction |
|---|---|
| `examples/ReceiptNotification.php` | Comments describe correlation and passive callbacks without a delivery listener, receipt mutation or timestamp changes; queue guidance defers until commit and preserves resend uncertainty. Runtime example code is unchanged. |
| `docs/upgrade-guide.md` | Replaces the dev-main/same-surface/no-migration promise with callback/limiter/queue changes, retained legacy queued events, secret-retention work and application-owned forward schema upgrades. |
| `docs/queueing.md` | Removes the three-attempt queued decorator, placeholder deletion and morph-alias class resolution. Uses normal `ShouldQueue`/`afterCommit()` support and explains worker, transaction, retry and channel-result limits. |
| `CONTRIBUTING.md` | Describes the two outbound events, passive callbacks and uncertain/local recording failures. Removes the unenforced measured-coverage claim and aligns checks with manifest/audit/dependency evidence. |
| `.docs/dev/reviews/packagist-publication-checklist.md` | Updates passive payload-only verification, native publish-once semantics, conditional actual Laravel 12/13 support, real-engine/upgrade checks, archive semantics and controlled provider evidence. Every release checkbox remains unchecked. |

## Actual verification, November 11

- Read the applicable `.agents` directives and compared documentation with
  current callback/retry/channel source and CI files.
- Confirmed queued notification handling and `Queueable::afterCommit()` in
  installed Laravel source. This is local framework evidence, not a new
  supported-version matrix run.
- `php -l examples/ReceiptNotification.php`: passed.
- Extracted the sole PHP example in `docs/queueing.md` and piped it to
  `php -l`: passed without creating a temporary file or executing the example.
- Checked 12 relative Markdown links across the five owned Markdown files:
  all resolved locally at that check.
- Reviewed the complete tracked diff and updated checklist; `git diff --check`
  for owned tracked paths passed. The checklist/task record are untracked, so
  that Git check does not constitute a staged/archive check for them.
- PHP lint printed the pre-existing Imagick version warning and still exited 0.
- No behavioral tests ran: changes are documentation and PHP comments only.
- `nov05_gateway_crosscheck` independently reviewed the owned documentation
  without executing tests and found no substantive blocker. Its two wording
  corrections were applied: provider-shape tests may use labelled synthetic
  cases when captures are unavailable, and Composer updates remain conditional
  on the toolchain/authorization check instead of an unconditional archive step.

## Limits and handoff

This packet changes explanations and release acceptance criteria. It does
not establish provider compatibility, framework/database coverage, safe
schema upgrades for every existing application, a finished release or
permission to execute the operational checklist. Existing Packagist URLs
and operational sections were retained, not independently exercised.
No credentials, external provider requests, dependency updates, commits,
pushes or deployment changes were used here.

The important review boundary is unchanged: callbacks retain only the latest
sanitized information, while irreversible sending and queue redelivery can
still leave uncertain outcomes. Release claims require actual evidence for
the candidate artifact, dependency graphs and advertised database engines.
