# Commit, remote CI and tagged distribution

**Date:** 2026-11-11, Africa/Nairobi. **Status:** authorized; execution in progress.

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
- Documentation commit, push, remote jobs and tag verification are pending.
  Append only observed results; retain exact SHA/run IDs and separate local
  from remote evidence.
