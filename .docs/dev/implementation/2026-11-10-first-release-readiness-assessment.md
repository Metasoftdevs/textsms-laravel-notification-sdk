# First-release readiness assessment

> Historical assessment. The owner subsequently requested implementation and
> approved `.docs/`/`.agents/` disclosure. See [November 11 status](2026-11-11-execution-status.md)
> for resolved code issues and the actual remaining external gates.

**Date:** 2026-11-10, Africa/Nairobi. **Assessor:** Codex.
**Scope:** assess commit and publication readiness against the approved
minimal behavior. No implementation, staging, commit, push, provider call,
toolchain update, repository visibility change or publication was performed.
This assessment records the next work; it does not authorize those operations.

## Conclusion and evidence

Accepted work is suitable for a branch checkpoint once the owner authorizes
committing. First-version publication is still blocked by specific unfinished
code and release evidence. Finishing the entire historical feature backlog
is not necessary to publish the approved minimal SDK.

Checked the working tree, current gateway/senders/provider/migration, public
examples and docs, November 9 implementation records, November 10 independent
readiness review and decision record, and the original publication checklist.
The local index is empty; no local tags exist. There are 38 modified tracked
files, 13 untracked test files and three untracked internal directories.
`git diff --check` passes on this assessment. Local Composer reports 2.7.6.

Latest independently recorded November 10 gate: **281 tests, 2117 assertions**,
seed 1794286324, plus PHPStan level 8, Pint and strict Composer validation.
This assessment did not rerun those tests. The November 9 root-run gate
independently records the same count under seed 1794253104. Neither establishes
the unexecuted PHP/dependency matrix or live provider behavior.

The November 10 review also records a candidate working-tree Git archive with
28 runtime files and no internal/test/docs material. This improves preliminary
archive evidence; it does not replace checking the exact committed release
artifact or installing it in a fresh application. No new archive or secret
scan was run by this assessment; repository/history privacy is not cleared.

## Before commits

1. Obtain owner authorization and use a review branch. Commit permission and
   push permission are separate under `.agents/commit_directives.md`.
2. Stage explicit source/test/config paths. Include all new regression files.
   Keep `.claude/` local; keep `.docs/` and `.agents/` uncommitted while D6 is
   unresolved. Existing accepted work need not wait for every release task.
3. Use coherent commits for runtime/tests, CI/distribution and public docs.
   Gateway changes overlap across accepted packets; do not invent supposedly
   isolated per-packet histories. Keep the supporting docs available for review.
4. Inspect the actual staged diff/list, run the required focused checks,
   `pint --dirty` and whitespace checks, then commit only the reviewed paths.
   Do not treat an authorized commit as authorization to push or publish.

## Engineering work recommended before the first public tag

| Work | Current source evidence and required outcome |
|---|---|
| T-04 remainder | Empty credentials still reach the provider; `balance()` returns decoded error/HTML payloads as data and the command can exit successfully. Add credential preflight and truthful balance validation/errors. Preserve approved D2 and passive ingress independence. |
| T-13 | All senders assign the model class directly instead of its morph alias. Associate through the relation and verify morph-map queries and configured identifier keys. |
| T-12 / D5 | Publishing still timestamps a `.stub` manually; schema retains enum status and attempt default0. Settle D5, use the agreed framework publish semantics, align the fresh schema, and handle any existing installations explicitly. Native publishing must be documented as publish once, not repeat-safe. |
| T-14 | Fake installation leaves previously resolved notification drivers cached; fake saves swallow all failures; fake validation/failure behavior and both doubles' bulk preflight differ from the real sender. Fix those bounded behaviors with regressions. No new delivery state simulator is required. |
| T-19 and relevant T-16 | Correct current public recipes and cover the final changed behavior. Examples still prescribe `SmsDeliveryReported`; the upgrade guide says dev-main needs no migration; contributing guidance retains delivery/coverage claims. The optional queue decorator still uses three job tries around direct sends and could repeat an unknown outcome. Remove/simplify that recipe rather than add a queue policy framework. |

Recommended order remains **T-04 remainder → T-13 → T-12 after D5 → T-14 →
final targeted coverage/docs**. Independent review and appropriate gates follow
each packet. There is no test-count or exhaustive-coverage target for its own sake.

## Evidence and operational gates before publication

- **T-07:** authorize and capture one real single send, a two-item bulk send
  and balance lookup. Verify the actual client-reference format/echo and
  response correlation; preserve sanitized fixtures. Provider idempotency
  remains unknown unless demonstrated. Do not require proof of exactly-once
  delivery. Confirm callbacks before advertising a verified live push contract;
  otherwise keep that support explicitly unverified/passive. No poller is needed.
- **T-17:** run the prepared six PHP 8.2/8.3/8.4 × lowest/stable Laravel 12 rows
  and dependency audit on the actual candidate commit. Local SQLite success
  and valid workflow syntax are insufficient. Remote CI state was not checked
  by this assessment.
- **T-21:** use a maintained Composer toolchain; fresh-install the exact
  candidate in Laravel, verify discovery, config/routes/migration publishing,
  cache commands and commands; test schema/JSON/column limits on the database
  engines the release claims to support. Inspect the exact release archive
  and repeat installation/smoke checks against the RC/tagged distribution.
- Finalize dated changelog/release notes, metadata and version/support claims.
  Verify public repository access, Packagist package/namespace ownership,
  maintainer permissions and update integration before authorized submission.
  Those account/remote states remain unverified here.

The old [publication checklist](../reviews/packagist-publication-checklist.md)
must be corrected before use. Its active-DLR transitions/event and conditional
poller requirements are superseded. Its migration publish-twice/no-duplicates
test is invalid for the framework publish-once contract. Keep the useful
artifact, CI, fresh-install and release-operator checks.

## Owner choices and work that can wait

- **D5:** settle migration/schema semantics before the first install contract.
- **D6:** decide public disclosure of internal folders and recipient data,
  including existing Git history, before a public push/visibility change.
  Export exclusions do not hide repository history. A history rewrite needs
  separate explicit authorization.
- **D7:** choose the actual framework support window. Laravel13 support is
  desirable but not an intrinsic prerequisite for releasing a package honestly
  scoped to Laravel 12. Laravel 12 security support ends February 24, 2027;
  a Laravel 12-only first release accepts that short support window. No widened
  constraint or Laravel 13 support claim is justified without its real matrix.
- **Q8:** the first consumer's delivery-status workflow is an adoption decision,
  not a passive-SDK publication blocker. Correct consumer examples without
  reintroducing callback application actions into this release.
- Rotate previously exposed `TEXTSMS_DLR_SECRET` values and assess historical
  rows/logs in affected deployments. This is an environment operation, not a
  prerequisite for a local source checkpoint or an automatic code change.

Active delivery precedence, polling, callback action hooks, history tables,
resend summaries/counters, exactly-once transport, failover frameworks and
future-provider features remain outside this release scope.

## References and verification limits

- [Current accepted implementation](2026-11-09-approved-minimal-resolutions.md)
- [Pathway status](2026-11-09-execution-status.md)
- [November 10 independent review](../reviews/claude/2026-11-10-pre-commit-and-publication-readiness-review.md)
- [Owner decision context](../decisions/2026-11-10-reliability-policy-decisions.md)
- [Laravel support policy](https://laravel.com/docs/12.x/releases#support-policy),
  checked November 10: Laravel 12 security fixes through February 24, 2027.
- [Packagist publication/versioning](https://packagist.org/about), checked
  November 10: public repository submission, namespace rights and VCS-derived
  versions remain operator checks.
- [Composer maintenance/downloads](https://getcomposer.org/download/), checked
  November 10: installed 2.7.6 is behind the maintained stable line; no updater ran.

The November 10 independent review treats Laravel 13 as a stable-release
blocker. This assessment treats it as D7's explicit product-scope choice.
Its claim that no live send ever ran is bounded here to the available
repository evidence: no sanitized live captures establish this candidate's
contract. No external deployment history is inferred.
