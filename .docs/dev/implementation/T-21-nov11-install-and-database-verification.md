# T-21 — Fresh Laravel 12/13 and isolated database verification

**Date:** 2026-11-11, Africa/Nairobi. **Status:** completed within the
delegated fresh-Laravel-12/13 and isolated-database verification scope.

## Boundary and plan

Use PHP 8.3 and the coordinator's isolated maintained Composer executable
to create a fresh Laravel 12 application outside the repository. Install
a controlled copy of the candidate package through a Composer path repository,
without changing repository vendor/lock files. Verify discovery, one-time
config/migration publication, migration, registered commands/routes, disabled
route boot and cache/optimization commands with synthetic configuration.

Exercise only the coordinator-owned MySQL 8.4/PostgreSQL 16 containers on
their dedicated localhost ports and synthetic database. Create isolated test
tables to verify schema defaults, JSON/multibyte persistence, correlation,
morph keys, passive callback invariants and storage-failure recovery. Never
touch the host's existing database service or stop/remove containers. Record
actual versions, commands and limits. Laravel 13 fresh-install verification
was subsequently assigned to this lane; its plan and evidence appear below.
Update the owned upgrade-guide schema targets separately.

No live provider call, user credential use, repository dependency mutation,
release tag, commit or deployment operation is included.

## Actual results

### Fresh Laravel 12 application

Created an application with official `composer create-project
laravel/laravel:^12.0` using maintained Composer 2.11.2 and PHP 8.3.13.
The resolved skeleton was v12.12.2, framework v12.72.0 and Guzzle v7.16.1.
Composer installed the candidate as `dev-main` from a controlled path
repository with mirroring enabled (`symlink: false`). Package discovery ran
successfully. The install/update reported no security vulnerability advisories.
This is a local candidate installation, not a published Packagist/tag install.

Published `textsms-config` and `textsms-migrations` once each. Exactly one
package creation migration was published as
`2026_11_11_074011_create_sms_dispatches_table.php`; `artisan migrate --force`
created the table in the fresh application's SQLite database. This does not
establish repeat-safe publication or an existing-installation schema upgrade.

Executed `build/nov11-fresh-boot-check.php` against actual application boot:

- Auto-discovery found the package provider and the three commands
  `textsms:balance`, `textsms:test` and `textsms:dispatch:show`.
- Enabled boot registered the authenticated `throttle:textsms-dlr` route.
- With `TEXTSMS_WEBHOOK_ENABLED=false`, a separate boot omitted that route.
- `config:cache`, `route:cache` and `optimize` succeeded. Subsequent boots
  retained discovery, commands and the enabled route, with both caches active.
- `optimize:clear` succeeded. A final boot reported both caches cleared,
  with discovery, commands, route and the migrated table still present.

Commands that contact the SMS provider were registered but were not invoked.
Configuration used synthetic credentials and a loopback-only fallback URL.
After the cache checks, refreshed the candidate with the coordinator's final
source and ran Composer `reinstall` for this package; it exited successfully.
The installed gateway and provider SHA-256 hashes matched the repository
files. A further actual application boot passed with discovery, commands,
enabled route and the migrated table intact. The database checks below used
that refreshed installation.

### Isolated database verification

Initial PDO attempts to ports 13386/15436 failed with connection refused.
Both original task containers were absent from `docker ps -a`. With the
coordinator's authorization, recreated only the two named task containers
using existing images and bindings restricted to `127.0.0.1`. Startup logs
reported MySQL 8.4.13 and PostgreSQL 16.15. No host database was accessed.

Executed `build/nov11-release-db-check.php` once successfully for each engine
after the source refresh. Each run passed **63 assertions** on PHP 8.3.13 and
Laravel 12.72.0. The database version queries confirmed **MySQL 8.4.13** and
**PostgreSQL 16.15**. MySQL session mode included `STRICT_TRANS_TABLES` and
`ONLY_FULL_GROUP_BY`.

For each engine, the script created separate dispatch and owner tables for
native integer, UUID and ULID morph key configurations. It verified:

- The package migration created a string status of length 16 and database
  defaults `pending` / attempt 1; a raw insert received both defaults.
- Nested JSON metadata retained Unicode, boolean and integer values.
  Accepted and failed multibyte response descriptions persisted as valid
  UTF-8 strings of exactly 255 characters.
- Native morph aliases, stored owner keys, relation loading and
  `whereMorphedTo` worked. Reordered two-item provider responses retained
  client-reference correlation and the correct owner associations.
- Actual authenticated HTTP callbacks for `Delivered`, `Failed` and
  `buffered` returned 200. Only `dlr_payload` changed; all other raw row
  attributes, including timestamps, remained equal. The latest sanitized
  payload replaced the previous payload. No SDK event or observed model
  retrieval/write event fired.
- A native database trigger rejected updates. The callback returned 500
  and retained the previous payload. A faked provider acceptance followed
  by the same database failure retained its prior pending row, exposed a
  provider exception with the underlying query error and accepted/resend
  recovery wording, emitted no outcome event, and made no resend attempt.
- A small representative legacy fixture converted the MySQL status enum
  or removed PostgreSQL's named status check constraint before narrowing
  status to a string of length 16 and changing the attempt default to 1.
  An existing `delivered` row and its attempt value 0 were preserved; a
  new custom string state was accepted with attempt default 1.

Each engine run used **12 faked provider requests and zero real provider
requests**. Cleanup completed through the script's `finally` block. Separate
database queries then confirmed **zero remaining `t21_` tables** in both
databases and **zero remaining `t21_` trigger functions** in PostgreSQL.
The coordinator owns stopping/removing the task containers; this lane did
not stop or remove them. The script passed a final PHP syntax check.

Coordinator cleanup subsequently verified the two named task containers were
stopped, then removed them with their anonymous volumes. A filtered
`docker ps -a` returned no remaining TextSMS task containers. The host's
existing database service was untouched.

This is bounded local evidence on these two versions, not a database CI
matrix or a claim covering every database configuration. The representative
legacy fixture does not validate an application's full existing schema,
indexes, rollback or deployment procedure.

### Documentation and scratch artifacts

Updated [the upgrade guide](../../../docs/upgrade-guide.md) with the exact
new schema targets: `status` is a string of length 16 with default `pending`;
`attempt_count` defaults to 1. Existing tables require an application-owned,
engine-aware forward migration. Existing row values and indexes must be
preserved; MySQL enum and PostgreSQL enum/check restrictions need explicit
handling. A default change does not rewrite historical attempt counts.

Ignored scratch scripts: `build/nov11-fresh-boot-check.php` and
`build/nov11-release-db-check.php`. The controlled candidate and application
are under the local temporary directory
`textsms-nov11-release-71542c8570224cd6ab80325d55058436`.

The complete runtime matrix, live provider-contract verification,
tag/publication and production migration verification belong to separate gates.
No package source, repository dependency files or maintained test files were
changed by this verification lane. No commit, push or release was performed.

## Fresh Laravel 13 follow-up

The coordinator reassigned the remaining fresh-Laravel-13 installation gate
to this lane before finalization. Plan: create one official Laravel 13
application with the same maintained Composer/PHP 8.3 executables; install
the final controlled candidate through the mirrored path repository; check
discovery, one-time config/migration publication, SQLite migration, route
enable/disable boot and cache/optimization commands with synthetic settings.
No additional real-database work or provider request is included.

**Result: passed.** The official `laravel/laravel:^13.0` skeleton resolved to
v13.11.0, framework v13.42.0 and Guzzle v8.3.1 on PHP 8.3.13. The original
`create-project` operation downloaded/extracted the skeleton and all 111
dependencies, then remained in optimized Composer autoload generation for
several minutes without further output. Stopped only that identified task
process and set the temporary application's `optimize-autoloader` to false.
Resumed with Composer `update metasoftdevs/textsms-laravel-notification-sdk`
using the final candidate path repository (`symlink: false`). This completed
successfully with one package install, no other package updates/removals,
normal PSR-4 autoload generation, automatic package discovery and no reported
security vulnerability advisories. The interrupted skeleton's remaining
application-key and SQLite initialization steps were completed explicitly.
This run verifies normal Composer autoloading; it does not establish that the
interrupted optimized Composer autoload step completed. Laravel's separate
`artisan optimize` command did pass, as recorded below.

Published `textsms-config` and `textsms-migrations` once each. Exactly one
package migration, `2026_11_11_132510_create_sms_dispatches_table.php`, was
published and migrated successfully with the skeleton migrations into the
fresh SQLite database. Executed the existing
`build/nov11-fresh-boot-check.php` against actual Laravel 13 application boot:

- The provider was auto-discovered and all three package commands were
  registered. No command contacting the SMS provider was invoked.
- Enabled boot exposed the authenticated, limited callback route. A separate
  boot with `TEXTSMS_WEBHOOK_ENABLED=false` omitted that route.
- `config:cache`, `route:cache` and `optimize` succeeded. Both cached and
  optimized boots retained discovery, commands, the enabled route and the
  migrated table, with configuration and route caches active.
- `optimize:clear` succeeded. A final boot confirmed both caches cleared and
  the same discovery, commands, enabled route and migrated table intact.
- Installed-version metadata confirmed framework v13.42.0, Guzzle v8.3.1 and
  the candidate package `dev-main`. Installed provider and gateway SHA-256
  hashes matched the final repository source.

The application is under the same task temporary directory in `laravel13`.
It used synthetic SMS settings and a loopback-only provider URL. There were
no provider requests and no additional real-database operations. This is a
local mirrored-candidate install rather than a published archive/tag install.
Repository source, dependency files and maintained tests were unchanged.

## Actual tagged archive follow-up

The coordinator subsequently pushed `v1.0.0-rc1` on the complete CI-green
commit `e57ee40` and downloaded the real GitHub tag ZIP. Each disposable
Laravel12/13 app replaced only the mirrored candidate with version
`1.0.0-rc1` from that verified ZIP. Both Composer updates/discovery scripts
completed successfully and reported no vulnerability advisories. All28
installed package files, the dist reference and actual application boot,
commands, limited callback route and existing migrated table were verified.
These checks close the earlier local-mirror-versus-tagged-archive gap. They
are not a public Packagist install or live SMS test. Exact hashes and evidence
are in [release execution](2026-11-11-commit-ci-and-tag-verification.md).
