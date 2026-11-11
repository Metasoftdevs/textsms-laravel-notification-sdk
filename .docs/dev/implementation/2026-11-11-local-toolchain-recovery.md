# Local Composer toolchain recovery

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex compatibility agent.
**Status:** real isolated installation, authorized root restoration, root
autoload generation and root offline install dry run completed successfully.
The coordinator owns the final source quality gate.

## Problem and bounded recovery

The coordinator reported four root-project dist installation attempts with
PHP 8.2/8.3 that extracted the locked packages but failed while cleaning
temporary package files. File access and ACL inspection failed even though
the parent directory permitted ordinary writes. A source fallback also
timed out. Adding existing 7-Zip to the task-local PATH did not resolve the
root-project cleanup failure. These are coordinator-reported attempts, not
additional attempts executed by this agent.

The reviewed lockfile required PHPUnit 11.5.56 and Laravel framework 12.72.0;
their Composer installation records were missing. The recovery preserves
that manifest and lockfile. It does not invent installation metadata or
edit Composer's implementation to suppress failures.

The authorized procedure is: copy the 134 valid installed package records
and package files into an inherited-permission temporary project, excluding
the two incomplete package directories and failed Composer extraction
directories; copy the official cached archives; run actual Composer install
offline; verify its resulting records against the lockfile; restore the
two actual installed payloads and generated autoload/metadata files; then
run root autoload generation and an offline install dry run.

Only the two package targets are replaced. Any previous targets are retained
under an ignored diagnostic backup after their resolved paths are checked
inside this project's vendor directory. Failed extraction directories are
left as diagnostics; no broad ACL or system-security change is part of the
recovery. No source, manifest, lockfile or Git-history change is authorized.

## Actual isolated installation evidence

- The first Python-created temporary directory allowed directory creation
  but denied opening its new `composer.json`. A new directory created with
  PowerShell `New-Item` passed a write probe and the complete staging copy.
- Staging copied 134 existing records and the valid package files, while
  excluding the two incomplete packages and failed temporary directories.
  Eight cached ZIPs were present with matching source/destination sizes.
- Official Composer 2.11.2, running on Herd PHP 8.3.13, performed exactly
  two real installs: PHPUnit 11.5.56 and framework 12.72.0. Installation and
  autoload generation exited 0 with network disabled.
- The resulting 136 installed packages exactly matched all 136 locked
  package names, versions and source references, with no missing or extra
  records. Native autoload probes reported framework 12.72.0 and PHPUnit
  11.5.56.
- A subsequent staged offline `composer install --dry-run` exited 0 and
  reported nothing to install, update or remove.

The offline commands warned that remote filter-list data could not be
refreshed. They are installation checks, not a fresh network advisory audit;
the coordinator's lockfile audit is recorded separately.

The observed directory-creation difference is a permission clue. It does
not establish the exact cause of the original root-project failures or
prove that antivirus, PHP ZipArchive or Composer itself caused them.

## Artifacts and final handoff

Staged project:
`%LOCALAPPDATA%/Temp/textsms-nov11-root-repair-454143f1`.
Its `actual-install.log`, `actual-install-dry-run.log`, lockfile and real
Composer-generated installed records retain the installation evidence.

The authorized root restoration completed with this agent as the sole
vendor writer. Each resolved package target was checked inside this
project's vendor directory before replacing it; previous targets and
generated metadata were retained in
`build/local-toolchain-backup-dd09a53a`.

| Root check | Actual result |
|---|---|
| Framework payload restoration | 1745 files, version 12.72.0; every file's SHA256 matched the actual staged installation |
| PHPUnit payload restoration | 975 files, version 11.5.56; every file's SHA256 matched the actual staged installation |
| Installed metadata | Actual Composer-generated records restored; staged 136-package graph exactly matched all locked names, versions and source references |
| Autoload restoration | Generated Composer files, `vendor/autoload.php` and actual PHPUnit binary proxies restored |
| Root manifest and lockfile | SHA256 unchanged throughout this recovery |
| Root `composer dump-autoload --no-scripts` | Exit 0 using official Composer 2.11.2 and Herd PHP 8.2.25 |
| Root offline `composer install --dry-run --no-scripts` | Exit 0; nothing to install, update or remove |

The backup contains `recovery-inventory.json`, `root-dump-autoload.log` and
`root-install-dry-run.log`. The original failed temporary directories were
not removed or subjected to ACL changes.

The coordinator was handed the restored tree immediately after both root
Composer checks passed. This agent did not start a competing full suite.
The subsequent PHP 8.2 suite, PHPStan and Pint results belong in the current
[execution status](2026-11-11-execution-status.md); they are not claimed by
this recovery record.

**Intent and invariant:** restore a usable local development graph from a
real lockfile installation, preserving source and dependency selection.
The package payloads and Composer state come from the successful installer;
metadata was not reconstructed by hand.

**Failure/recovery and limits:** retained backups make the bounded package
replacement reviewable. The original extraction/cleanup cause is not proven
resolved in place; staging with ordinary inherited permissions outside the
Desktop project was the successful workaround. This establishes local
dependency usability, not live provider compatibility or release acceptance.
