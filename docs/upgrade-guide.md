# Upgrade guide

The first stable release is planned. Consumers tracking `dev-main` can
already have installed migrations, queued jobs and application listeners.
Review the exact commits and [CHANGELOG](../CHANGELOG.md) when upgrading;
development versions do not promise an unchanged surface or schema.

## Existing development installations

### Passive delivery reports

Callbacks now replace only the matched dispatch's sanitized `dlr_payload`.
They preserve submission state, identifiers and every timestamp, including
`updated_at`. They do not classify delivery, invoke Eloquent observers or
perform application actions. `SmsDeliveryReported` remains loadable for
compatibility but the default gateway emits no new delivery events.
Previously queued events can still run under the host's queue policy.

Remove assumptions that a callback updates receipt status, sets
`delivered_at`, or triggers a delivery listener. Existing delivery columns
and legacy states remain readable; this behavior change needs no backfill
or schema change. The legacy `awaitingDlr()` scope still checks `status=sent`
and `delivered_at IS NULL`; use `whereNull('dlr_payload')` when looking for
missing callback information. See [webhooks](webhooks.md) and
[events](events.md).

### Callback authentication and rate limiting

Reuse `TEXTSMS_DLR_SECRET` for the URL's `?token=` value or the supported
`X-TextSMS-Token` header. The named limiter defaults to 600 authenticated
callbacks/minute, configurable through `TEXTSMS_WEBHOOK_RATE_LIMIT`.
Remove a previously published `throttle:60,1` entry if only the new named
limit is wanted; otherwise it remains an additional restriction. Update
published route copies to authenticate before `throttle:textsms-dlr`.
See [webhook upgrade guidance](webhooks.md).

Earlier installations can retain callback tokens in rows and logs. Rotate
the secret in affected deployments, update callback registration and assess
historical retention. Updating package files does not rotate deployed
credentials or delete existing records.

### Sending, queues and stored outcomes

Single and bulk sends permit one additional Laravel HTTP attempt only for
structured cURL 6/7 DNS/connect failures. Responses and ambiguous failures
do not qualify; send redirects are disabled. This is not a provider
idempotency or exactly-once guarantee. A `failed` dispatch or
`SmsProviderException` can describe unknown acceptance or a local recording
failure after acceptance. Inspect recovery diagnostics before resending.
See [operations](operations.md) and [queueing](queueing.md).

Immediate outbound listener failures are contained after outcome recording.
Queued listeners and after-commit work follow host queue handling. Review
test-helper validation, persistence and binding changes in the changelog;
do not assume a fake makes a broken database or stale channel binding safe.

### Migrations and supported runtimes

Publish a package creation migration once for a new installation. Follow
Laravel's migration-date configuration and review the generated filename.
Re-publishing is not an upgrade strategy and may create another table-creation
migration. Editing a creation migration does not alter a table where it
already ran. Compare the installed schema with the target release, then
prepare and verify an application migration for any required change;
preserve existing dispatch data and notifiable keys.

The current creation schema uses `string('status', 16)` with default
`pending` and an `attempt_count` default of 1. Earlier development schemas
used a status enum and defaulted attempts to 0. Existing installations must
handle those changes in an application-owned forward migration; changing a
default does not rewrite historical attempt values. Preserve rows, indexes
and existing status values. Convert MySQL's enum explicitly, and account for
any PostgreSQL enum/check restriction before accepting new string states.
Do not assume a generic column-type change removes every engine's old
constraints. Verify the upgrade and rollback/recovery plan on the actual
database engine before deployment.

Check the target tag's Composer constraints, README and tested runtime
matrix. Laravel 12/13 or database support must have matching dependency,
fresh-install and engine evidence. A constraint change or SQLite-only test
run does not establish compatibility with every runtime or database.

## Reporting upgrade issues

Open an issue with the previous and target version or commit, a minimal
reproduction, relevant schema/configuration and a sanitized stack trace.
Exclude credentials, callback secrets, recipients and message bodies.
