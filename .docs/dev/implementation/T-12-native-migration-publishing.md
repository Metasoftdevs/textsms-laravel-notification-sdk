# T-12 — Native publishing and first-release schema

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex.
**Status:** local implementation and native-publishing review accepted;
real database/fresh-install evidence is tracked separately in T-21.

## Resolution and implementation

The owner requested resolving remaining release issues with simple framework
behavior. Use `publishesMigrations()` for the migrations directory, rename the
creation stub to discoverable `.php`, use `string('status',16)` and default
attempt count1. Existing legacy delivery columns remain readable; passive
callbacks do not update them. Morph columns follow host UUID/ULID configuration.

Native publishing respects `database.migrations.update_date_on_publish`.
Document **publish once**; there is no duplicate-table guard or promise that
publishing twice is safe. Testbench now loads the same discoverable PHP migration
instead of manually requiring the stub. Alternate-connection tests use its
new path; the provider publication-group check accepts a directory source.
Pint's stale stub exclusion was removed.

## Actual evidence, November 11

| Check | Result |
|---|---|
| Publishing/schema regressions before fix | 3 failed, 4 assertions; seed 1794379693 |
| Focused migration + bulk after corrections | 71 passed, 453 assertions; seed 1794379978 |
| Independent review | `nov05_gateway_crosscheck` accepted source scope |
| Independent native CLI probes | Two isolated one-time publishes passed: date rewrite false preserved filename, true re-dated it; each produced one file |

The first focused integration attempt found Windows separator assertions and
Laravel's static publishable-migration registry leaking between Testbench apps.
The test now normalizes separators and restores that static state around its
own application. Neither issue requires a new package publishing mechanism.
The old anonymous owner fixture correction is documented in T-13.

## Existing installations and recovery

Changing this creation migration does not upgrade a previously migrated table.
Do not delete dispatch data, overwrite a host's already-run migration, or
re-publish to repair it. Existing installations need an application-owned
forward migration to the documented string/default1 targets, preserving rows,
indexes and owner keys. Remove legacy enum/check restrictions explicitly for
the actual engine; a portable `string()->change()` alone does not establish
that PostgreSQL CHECK constraints disappeared. Review aliases/key types with
T-13 when migrating historical data. No live application schema was changed.
