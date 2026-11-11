# Public Packagist install and authorized live validation

**Date:** 2026-11-11, Africa/Nairobi. **Status:** public install and live
single/notification/bulk submission passed; owner confirms all four handset receipts.

The owner reports the package published on Packagist and authorizes installation
in the specified `Metasoftdevs2fa/laraveldemo` application, using its temporary
environment credentials and two supplied live test recipients. Refer to those
recipients only as A/B in this record; do not copy credentials or phone numbers
into package documentation, committed files or console evidence.

## Planned boundary

1. Check the application's instructions, existing dependencies/configuration and
   database. Preserve its existing work and unrelated tables. Install the RC
   from public Packagist with maintained Composer; no local SDK override.
2. Publish the package config/creation migration once as needed. Run only the
   package migration if its table is absent. Never reset the application DB.
3. Confirm the real TextSMS sender and outbound credential presence without
   revealing values. Use identifiable short ASCII test messages.
4. Send one single SMS to A, one to B, then one two-item bulk batch to A/B:
   four intended SMS messages over three SDK operations. Exercise the Laravel
   notification channel for one single and the actual sender for the others.
   Use default generated26-character client references to test the open contract.
5. Capture sanitized provider request/response evidence, correlation, stored
   rows and lifecycle outcomes. Prevent duplicate probe execution; do not
   automatically resend ambiguous/accepted attempts. Distinguish provider
   acceptance from handset delivery. No callback registration is inferred.
6. Record actual results and any required follow-up. Keep temporary tools,
   sensitive credentials and recipient details outside committed artifacts.

## Preflight evidence

Public Packagist metadata now exposes `v1.0.0-rc1` at the expected release
commit `e57ee40f9168b12d68d1898834019994ce74df46`. The selected application
exists, has existing vendor/dependencies and is not inside a Git worktree.
No applicable AGENTS.md was found in its checked ancestor/application paths.
No provider request has been made during this preflight.

## Installation and application setup

Installed `v1.0.0-rc1` from public Packagist in the owner's specified demo,
using maintained Composer2.11.2 and PHP8.3.13. Installed reference matches
`e57ee40f9168b12d68d1898834019994ce74df46`. The application uses Laravel12.20.0,
Guzzle7.9.3 and SQLite. Its existing path repository serves a different 2FA
package; there is no local/path override for the SMS SDK.

Composer added exactly one package and changed no existing dependency versions.
Backups of the application Composer files are under ignored
`build/live-demo-backup/`. The original require operation took several minutes
generating optimized autoloads, then completed successfully, including discovery
and root post-update scripts. The subsequent normal PSR-4 autoload-generation
workaround also succeeded and restored the app's original optimization setting.
An earlier bootstrap probe run before installation finished could not load the
new model; it made no provider request and is not a package-defect finding.

Published `config/textsms.php` and exactly one creation migration,
`2026_11_11_155210_create_sms_dispatches_table.php`. Ran only that package
migration, preserving existing application tables. No environment values or
application source were changed. The actual TextSmsGateway binding and package
notification channel were verified before sending. Installed config reads the
temporary credentials from the app's existing environment.

Composer reported55 security advisories affecting15 packages in the existing
demo dependency graph. The before/after lock comparison verifies those existing
versions were unchanged; upgrading that graph is a separate follow-up, not a
failure of the four live submissions or the SDK's separately audited release graph.

## Live operations and persisted results

**Run:** `sdk-live-20261111-1855`.
**Actual time:**18:54:55–18:55:04 EAT (15:54:55–15:55:04 UTC).
**Transport:**three real HTTPS POST requests, three HTTP200 responses, no request
retries observed and no application-level resends. Four short ASCII test messages
were submitted: one single to each approved recipient and one bulk item to each.

| Case | Application path | Dispatch ID | Provider message ID | Stored result |
|---|---|---|---|---|
| Single A | Resolved SmsSender |1 |1003363797 |sent /200 |
| Single B | Laravel anonymous notification, `sms` channel |2 |1003363812 |sent /200 |
| Bulk A | One two-item sendBulk call |3 |1003363833 |sent /200 |
| Bulk B | Same sendBulk call |4 |1003363834 |sent /200 |

Each row has its expected normalized recipient, a distinct generated26-character
client reference, a provider ID stored as a string, attempt_count1, sent_at set,
failed_at null and no delivery-report payload. Exactly four SmsDispatchAccepted
events and zero SmsDispatchFailed events were observed. Laravel's NotificationSent
event returned dispatch2 for the real notification case.

A separate read-only verification passed47 assertions covering HTTP counts,
results, normalization, stored state/timestamps, event counts, notification result,
reference uniqueness, provider ID correlation, text bounds and unchanged existing
dependency versions. It made no additional provider request.

## Actual provider contract captured

- Both endpoints accepted the SDK's generated26-character client references.
- Single responses use `responses[]`, `response-code:200`, description `Success`,
  numeric messageid and numeric networkid. They do not echo clientsmsid; do not
  claim single-send reference round-trip evidence from these responses.
- Bulk responses use `responses[]`, the same success fields, string messageid,
  numeric networkid and string clientsmsid. Both client references echoed
  verbatim and map to the correct stored rows/provider IDs.
- In this one run all four provider IDs were distinct. This does not establish
  global ID scope, provider deduplication/idempotency, replay behavior, failure
  contracts or every supported sender/account configuration.

The original ULID-acceptance concern is resolved for the tested account/endpoints;
bulk reference echo is directly established. Single echo is absent, rather than
unverified input acceptance. Balance and callback registration/push contracts
were not exercised in this task.

## Evidence and handset confirmation

Sanitized request/response captures and verification summaries are retained under
ignored `build/live/sdk-live-20261111-1855/`. The send probe claimed an exclusive
run ledger before any network operation, and refuses to repeat that run. No
credential or recipient value is included in this public-facing evidence record.
The actual recipients/bodies remain in the designated app's normal dispatch table.

Public Packagist exposes `dev-main` and `v1.0.0-rc1`, and the repository responds
HTTP200 without authentication. Automatic-update configuration was not established
from the public response and is not claimed as verified. Package source, tags and
published release are unchanged; the test did not commit or publish a new version.

Provider acceptance is verified. The owner answered the async confirmation
question: both phones received both messages. Handset receipt of all four tests
is therefore manually confirmed, separately from the HTTP/database assertions.
No delivery callback was received, no delivery timestamp/status was synthesized,
and no public callback registration or application action was added. Rows remain
`sent` under the approved informational delivery-report policy.

## Documentation handoff

On November11 the owner authorized committing and pushing this validation record,
the publication checklist and the updated execution status. Only those three
documentation paths belong to this handoff; temporary tools, captures and app
files remain outside the commit. No additional SMS or release tag is required.
