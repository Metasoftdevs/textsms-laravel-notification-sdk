# Commit, remote CI and tagged distribution

**Date:** 2026-11-11, Africa/Nairobi. **Status:** commits/push and complete
remote CI passed; annotated RC tag pushed and actual tagged archive verified.
Tagged-archive installation and boot checks passed on Laravel12 and13.

## Owner decisions and scope

The owner authorizes committing and pushing the reviewed candidate, executing
the complete remote CI matrix and verifying a tagged distribution. Live SMS
and reference confirmation will run in a live test Laravel application after
package publication. It remains unverified evidence, but is no longer a
pre-publication gate. The owner accepts retaining the recipient in historical
commits; keep the current redactions and do not rewrite history. Further
optional polishing is deferred until after publication.

Use the actual `.agents/commit_directives.md` (plural filename; no singular
file exists). Stage explicit reviewed files, keep implementation/tests/config
together and public/internal documentation in a separate commit, use
Conventional Commits and list every affected path under `Files modified:`.
No generated tools, vendor, lockfiles, private settings or environment files
are included. Existing local verification remains dated November11 evidence.

## Execution pathway

1. Recheck staged source/config/tests and required dirty-Pint/whitespace gates.
   Commit the integrated dispatch, passive callback, migration, morph-map,
   test-double and Laravel12/13 changes with their regression tests/workflows.
2. Commit public documentation, release notes, approved `.docs/` and `.agents/`,
   including this explicit owner amendment. Prepare `v1.0.0-rc1`, the first
   version in the publication checklist; do not claim stable/live validation.
3. Push normally to the existing `origin/main`. Monitor both workflows on the
   exact pushed SHA:12 test rows plus PHPStan and Pint. Correct only failures
   necessary for the requested gate; no unrelated polishing or force-push.
4. After green CI, create an annotated RC tag, verify its Git archive from the
   committed tree and inspect its package file inventory/hash/metadata. Verify
   installation from the tagged candidate where access permits; push the tag
   normally and confirm it resolves to the accepted commit.
5. Record actual results and links. A tag is not a GitHub release or Packagist
   registration. Public visibility/Packagist maintainer access remain account
   operations; live test credentials and recipients are not needed in this task.

## Remote access preflight

The GitHub connector returned404. The origin's existing Git credential helper
successfully accessed the GitHub API without printing or persisting credentials.
The repository is private, not archived, uses `main` and grants push permission
but not admin/maintain permission. Remote `main` matches local base `f6b7627`;
there are no remote or local tags. Repository visibility was not changed.

## Actual execution evidence

- Implementation commit: `1a6a2da5e2e6c08290624854be85571e097bcdc5`,
  `fix(sdk): harden dispatch and callback contracts`. It includes51 explicit
  paths (50 Git change entries because the migration is a rename). Its message
  lists both rename paths and all other affected paths under `Files modified:`.
- Pre-commit dirty Pint and staged whitespace checks passed. The initial add
  exposed an empty stale `.git/index.lock` dated November1. Verified no active
  Git process, removed only that stale lock, and staged the reviewed paths.
- Post-commit status contains only the intended public/internal documentation,
  directives and documented example. No generated/private files were staged.
- Documentation commit: `e57ee40f9168b12d68d1898834019994ce74df46`,
  `docs(release): prepare the first release candidate and evidence`. Its65
  explicit paths include approved internal documentation/directives and omit
  `.claude`, environment files, vendor, lockfile and generated verification tools.
- Initial staging exposed whitespace in seven previously untracked Markdown
  files. Preserved hard breaks with backslashes and removed empty EOF lines;
  the staged whitespace gate passed before committing. Evidence dates/content
  were preserved. Dirty Pint and local links also passed; root status was clean.
- Normal push advanced `origin/main` from `f6b7627` to `e57ee40`; no force-push,
  amendment or historical rewrite occurred.

## Complete remote CI on the tagged commit

Both push-triggered workflows completed successfully on exact commit
`e57ee40f9168b12d68d1898834019994ce74df46`:

- [Tests run43043180660](https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/actions/runs/43043180660):12 jobs, all completed/success, including each dependency install,
  manifest validation and Pest step.
- [Static run43043180859](https://github.com/Metasoftdevs/textsms-laravel-notification-sdk/actions/runs/43043180859):PHPStan level8 and Pint both completed/success. The validation and dependency
  audit steps also completed/success.

| Framework | PHP | Lowest dependencies | Stable dependencies |
|---|---|---|---|
| Laravel12 |8.2 |Pass |Pass |
| Laravel12 |8.3 |Pass |Pass |
| Laravel12 |8.4 |Pass |Pass |
| Laravel13 |8.3 |Pass |Pass |
| Laravel13 |8.4 |Pass |Pass |
| Laravel13 |8.5 |Pass |Pass |

These are actual Ubuntu GitHub Actions results, closing the formerly
unverified matrix rows. They are separate from the dated local381-test runs;
no remote assertion count or unobserved dependency patch version is invented.

## Annotated tag and actual distribution

- Created and normally pushed `v1.0.0-rc1` after green CI. Remote tag object:
  `0e20d9af3b3401ba3e4897b596b30a9209b65cd6`; its peeled commit is exactly
  `e57ee40f9168b12d68d1898834019994ce74df46`.
- `git archive` from that tag contains28 runtime files. Every file equals its
  committed blob; the plain PHP migration and Composer discovery metadata
  are present. Internal/docs/tests/settings/environment/vendor/build/lock
  files are absent. Git ZIP SHA256:
  `0d395dc8ecd995250c1c3c5fe71d09c755a9f6278cc9adaf0099a15b058b2403`.
- Downloaded the actual GitHub ZIP for the remote tag using existing Git
  authentication in memory, without logging/persisting credentials or signed
  redirects. That ZIP also has28 files, every byte matching the tagged commit.
  GitHub ZIP SHA256:
  `2168ddafe2dd16a3aa68d825c28e64b003d93a3c3c1b024fa7829093c3a7206d`.
- Both archives have the same content-manifest SHA256:
  `0d94822a0d8c4284f72ebf167125856e2e8bc74155d90dd57a6345d83ee87f95`.
  Different enclosing directories/ZIP metadata explain their differing ZIP hashes.
  Machine-readable inventories and retained archives are under ignored
  `build/release/`.

## Installation of the actual tagged ZIP

Installed the downloaded, byte-verified GitHub tag ZIP into the two existing
disposable Laravel test applications through a local Composer package repository
declaring version `1.0.0-rc1` and the actual tag commit reference. Each Composer
update changed only the SDK from its mirrored `dev-main` candidate to the RC,
completed discovery/autoload scripts, exited0 and reported no vulnerability
advisories. This is actual archive installation, not a Packagist submission or
an assertion that the private repository is publicly installable.

For both PHP8.3.13 applications (framework12.72.0 and13.42.0), verified:

- Composer installed version `1.0.0-rc1`, installation source `dist` and the
  exact tagged commit reference. All28 installed files match the tagged ZIP;
  there are no missing or extra package files.
- Actual application boot auto-discovers the provider and all three commands,
  exposes the authenticated/limited callback route and retains exactly one
  published creation migration with the migrated table present.
- Normal PSR-4 Composer autoloading is used in these disposable apps, consistent
  with the earlier bounded fresh-install verification. No SMS command/provider
  request or new real-database work was run. Root source/vendor/lock stayed unchanged.

The initial temporary verification script used Windows' default text encoding
for Composer JSON and failed to decode Unicode metadata. Explicit UTF-8 fixed
the harness; only the subsequent completed checks above are acceptance evidence.
Machine-readable results are `build/release/tagged-install-verification.json`.

## Publication boundary

The repository remains private and this authenticated identity has push but
not admin permission. No visibility change, GitHub release or Packagist
submission has occurred. Public Packagist publication needs a publicly
accessible repository and the maintainer's account action. Live SMS testing
is deliberately deferred per the owner's instruction, not reported as passed.
