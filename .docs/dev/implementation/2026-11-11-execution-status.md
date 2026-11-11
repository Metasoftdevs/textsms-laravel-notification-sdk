# Release resolution status — November 11

**Date:** 2026-11-11, Africa/Nairobi. **Coordinator:** Codex.
**Status:** local implementation and independent review complete. The two
Laravel13 dependency graphs, fresh Laravel12/13 apps, real database probes and
controlled archive pass. Root vendor is restored and the final root gate passes. Remote
CI and publication are not accepted gates yet. The owner now authorizes
commit/push, full remote CI and tagged-distribution verification; execution is
tracked in [release execution](2026-11-11-commit-ci-and-tag-verification.md).
Live SMS verification is explicitly deferred until after publication; historical
recipient retention is accepted. Neither remains a pre-publication blocker.
This supersedes the November 9 pathway for current work.
The local-gate snapshot below predates commits. See release execution for
current commit/push/tag results; no live-provider send or history rewrite is planned.

## Implemented resolutions

| Area | Current result and record |
|---|---|
| D1–D4 | Preserved: isolated outbound events, one proven cURL6/7 retry, passive callback payloads and authenticated configurable600/minute limiting. |
| T-04 remainder | Accepted: outbound credential preflight and truthful balance HTTP/provider/JSON failures, preserving successful free-form objects. [Record](T-04-credentials-and-balance.md). |
| T-13 | Accepted: native relation association in real/fake/log senders, aliases and actual model keys. [Record](T-13-native-morph-association.md). |
| D5 / T-12 | Implemented minimal native publishing and settled fresh schema: discoverable PHP migration, string16 status and attempt default1. Publish once; host-controlled redating and forward migration for old tables. [Record](T-12-native-migration-publishing.md). |
| T-14 | Accepted: honest persisted fake/log behavior, full bulk preflight/atomic saves, cached-driver replacement and isolated publication. [Record](T-14-test-double-fidelity.md). |
| T-19 | Current example/upgrade/queue/contributing/checklist corrections implemented. Unsafe optional retry recipe removed; no new framework. [Record](T-19-nov11-public-documentation.md). |
| T-18 | Final lowest/stable13 graphs each pass381 tests/2504 assertions and PHPStan8. The other ten declared matrix rows remain unverified. [Compatibility evidence](T-18-laravel13-compatibility.md). |
| Guzzle compatibility | Native transfer statistics preserve D2 across Guzzle7/8; no text parsing or broader retry permission. Independent controls passed on both. [Review](2026-11-11-independent-source-review.md). |
| T-08B / D6 | `.docs/` and `.agents/` approved for commits. `.claude/` is local-permission-only and ignored. Current recipient references redacted; owner accepts keeping the recipient in Git history. [Record](T-08B-current-file-privacy.md). |
| T-21 | Fresh Laravel12.72/13.42 install/discovery/publication/migration/cache checks pass; MySQL8.4.13 and PostgreSQL16.15 each pass63 checks. [Install/database evidence](T-21-nov11-install-and-database-verification.md). |
| T-08A | Working-tree archive flaw found and fixed: explicit generated/local/environment exclusions. Controlled Composer ZIP has28 runtime files, every file matching root, and no exclusion markers. [Artifact evidence](T-08A-nov11-candidate-artifact.md). |

## Status against the complete November plan

| Packets | Disposition |
|---|---|
| T-01,T-02,T-05 | Earlier acceptance retained by the final regression suite; no policy reopened. |
| T-03,T-04,T-06 | Approved passive callback, bounded retry and authenticated limiter behavior implemented; credential/balance remainder now closed. |
| T-07 | Owner defers live provider/reference tests to a live Laravel test application after publication; evidence remains unverified. |
| T-08A / T-08B | Controlled archive accepted; current privacy/disclosure resolved, historical recipient retention accepted by owner. |
| T-09,T-15,T-20 | Earlier event serialization, lazy channel registration and hygiene acceptance retained; final contract-only kernel correction reviewed. |
| T-10 | Deferred outside approved passive scope; no poller or reconciliation requirement added. |
| T-11 | Passive-support wording accurate; provider-specific live claims remain provisional until T-07. |
| T-12,T-13,T-14 | Native migration/schema, morph association and test-double fidelity implemented and independently accepted. |
| T-16 | Required provider/binding/normalizer/command and accepted behavior regressions exist in381 tests; no coverage-percentage or exhaustive-coverage claim. Old poll-command criteria are superseded by passive scope. |
| T-17,T-18 | Twelve-row CI prepared; two final13 dependency graphs pass. Remaining matrix/remote exact-commit evidence open. |
| T-19 | Current public docs/examples/recipes aligned; historical extraction/review material remains clearly marked. |
| T-21 | Candidate archive, fresh12/13 installs and real database checks accepted locally; exact release/authorized publication gates remain. |

## B1–B9 crosscheck (dispositions)

| Review inference | Disposition |
|---|---|
| B1: no real SMS ever sent | Too absolute. Candidate live send/reference behavior remains unverified. Owner explicitly defers confirmation to a live test Laravel application after publication; no billable request is inferred. |
| B2: recipient/private folders prevent publication | Current recipient is anonymized and folder disclosure is resolved. Owner accepts keeping the value in historical commits; no history rewrite or remote force-push is required. |
| B3: schema unsettled | Fresh schema and native publishing resolved; old tables need an application-owned forward migration preserving rows/indexes/keys and engine-specific enum/CHECK changes. |
| B4: morph map ignored | Fixed and independently reviewed;18 mapped sender/operation/key cases pass. Existing historical data is not silently rewritten. |
| B5: CI never ran | Historical absolute claim unproven. The candidate is uncommitted, so it has no exact remote commit gate. The GitHub connector returned404; account/visibility/CI state cannot be inferred from that. Prepared12-row matrix plus actual local runs are recorded separately. |
| B6: Laravel13 support | Dual-major source/toolchain work now exists and final lowest/stable13 PHP8.3 graphs pass. Complete the declared matrix on the exact release commit. Laravel13 is a support-scope choice, not an intrinsic Packagist requirement. |
| B7: balance falsely succeeds | Fixed: invalid HTTP/provider/JSON outcomes now raise the package exception and the command returns failure. |
| B8: fake validates differently | Corrected at existing sender boundaries; validation, storage, bulk preparation and event/failure behavior are covered by focused tests/review. |
| B9: release checks/toolchain | VerifiedComposer2.11.2 used; fresh installs, both real databases and controlled candidate archive pass. Root vendor restored; exact committed/tagged/remote gates remain explicit. |

## Toolchain and installation evidence

The old ignored local lock reported37 advisories across10 packages when checked
with maintained Composer. A fresh PHP8.2-compatible graph resolved to patched
dependencies and audits clean (no advisories/abandoned packages). Installation
hit Windows ZIP cleanup/permission failures. Recovery completed through an
actual offline Composer install in an inherited-permission temporary directory:
134 valid packages copied, the two missing locked packages genuinely installed,
then their payloads and generated Composer/autoload files restored to root.
All copied payload hashes matched. Root autoload generation and Composer's
offline install dry-run exit0, reporting nothing to install/update/remove.
The root manifest/lock bytes did not change during recovery. Actual recovery
and limits are in [toolchain recovery](2026-11-11-local-toolchain-recovery.md).

Composer2.11.2 was downloaded from official metadata and SHA256 verified:
`dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028`.
The ignored workspace tool is `build/tools/composer-2.11.2.phar`.
Windows denied writing the protected global Composer path, so plain `composer`
still invokes2.7.6. Release checks use the verified workspace tool; a global
replacement requires an administrator action. The original binary was backed
up in task temp before the unsuccessful replacement; no global change is claimed.

MySQL8.4.13 and PostgreSQL16.15 ran in disposable task-only Docker containers
bound to localhost ports13386/15436, with synthetic credentials and no host
data mounts. Existing MariaDB3306 was untouched. After successful probes and
zero remaining task-table/function checks, the coordinator stopped and removed
both named containers and their anonymous volumes; filtered `docker ps -a`
returned no task containers. Actual engine checks are in the T-21 record.

## Completed final gates, November11

| Check | Actual evidence |
|---|---|
| Root Laravel12.72, PHP8.2.12 |381 passed/2504 assertions, seed1794387001,72.44s,exit0; PHPUnit11.5.56 |
| Laravel13 stable, PHP8.3.13 | 381 passed/2504 assertions, seed1794386001, exit0 |
| Laravel13 lowest, PHP8.3.13 | 381 passed/2504 assertions, seed1794386002, exit0 |
| PHPStan level8 | Root12 and both final13 graphs: no errors,exit0; no new suppression |
| Pint | All source/tests/config/routes/database/examples pass; one import order corrected |
| Composer strict metadata | Valid,exit0 using verified2.11.2 |
| Dependency audit | Final live root `audit --locked` reports no advisories,exit0; both candidate13 resolutions also clean at recorded checks |
| Fresh Laravel12 | Actual Composer path install/discovery/publish-once/migrate/routes/cache/optimize pass |
| Fresh Laravel13 | Same actual application checks pass; normal PSR-4 autoloading used after interrupting a stalled Composer optimized scan; Laravel optimize passes |
| Root vendor/platform/style | Real locked graph restored; install dry-run has no operations; production platform requirements and all-path Pint pass |
| Real databases |63 checks each;12 faked requests each;zero real provider requests |
| Controlled Composer archive |28 files; SHA256 `bfed586d2ae40c8d47389c8a303c2a19f2b6fc304c23d5591448a87c9cfccd61` |

The two final13 runs use the recorded49-file snapshot; subsequent test-only
Pint import ordering changes no assertions or runtime source. The final root
suite includes that formatting. Source corrections have independent acceptance;
evidence gates retain their separate limits. Final local Markdown checks pass45
links in19 files; the known-recipient current-text check finds zero matches
and zero unreadable files. Final `git diff --check` passes and the Git index
remains empty. No commit, push, tag, provider request or publication occurred.

A direct root Composer archive attempt failed while traversing an unreadable,
ignored scratch directory created by an earlier temporary-directory probe.
The controlled archive remains the accepted byte-matched candidate evidence.
The actual clean committed/tagged distribution still needs its release check;
neither an archive-command failure nor successful staging is claimed as that pass.

## Remaining external and final gates

- Live single/bulk/reference confirmation is owner-deferred until after
  publication in a test Laravel app. It remains unverified evidence, not a
  pre-publication gate; sanitized future captures must not be replaced by fakes.
- Historical recipient retention is accepted by the owner; preserve current
  redactions and do not rewrite history.
- Commit/push, full remote CI and tagged-distribution verification are now
  authorized. Actual results belong in the release execution record. Public
  visibility and Packagist account operations remain separate account steps.
- The complete12-row workflow must run on that exact commit. Only two final
  local13 rows are accepted here; startup of portable PHP8.4/8.5 is not a
  successful matrix row. One delegated escalation was not executed because
  automatic approval review reached a usage limit; this was not a safety verdict.
- Operational DLR-secret rotation/retention in any affected deployments remains
  environment-owned. No deployed config, callback registration or historical
  database/log cleanup has run.

Q8 remains a consuming-application adoption choice. The SDK does not promise a
delivery-status event or domain workflow; passive storage does not require
polling, active precedence, callback hooks, resend counters or a history table.

## Short release pathway

1. Local implementation, independent review, root Laravel12 gate, two13
   dependency graphs, fresh12/13 applications, real databases and controlled
   distribution checks are complete. Use their bounded evidence above.
2. Owner defers live verification until after publication and accepts the
   historical recipient; no further decision is needed on either for this task.
3. With the owner's authorization, commit/push the reviewed source/tests,
   required configuration and approved documentation/directives, preserving
   packet provenance. [Commit directives](../../../.agents/commit_directives.md)
   require: "Create a commit only when the project owner authorizes it."
4. Run the complete matrix/audits on that exact remote commit, inspect its
   actual distribution and fresh tagged installs; close any resulting defect.
5. Confirm public repository/Packagist maintainer access and obtain publication
   authorization, then publish the release candidate. Promote to stable only
   after its feedback and repeated exact-release checks pass. Use the
   [publication checklist](../reviews/packagist-publication-checklist.md).
