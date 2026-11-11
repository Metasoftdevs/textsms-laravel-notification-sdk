# Candidate distribution archive verification

**Date:** 2026-11-11, Africa/Nairobi. **Reviewer:** `nov05_gateway_crosscheck`.
**Disposition:** controlled candidate archive accepted after correcting local
archive exclusions. This is not acceptance of a committed/tagged release archive.

## Boundary and reproduction

Create an isolated staging tree from current `src/`, `config/`, `routes/`,
`database/`, `composer.json`, README, LICENSE and `.gitattributes`. Add only
synthetic exclusion markers; never copy actual environment files, credentials,
lockfile contents, dependencies or local tools. Initialize a scratch Git repository
without commits. Run the verified local Composer 2.11.2 PHAR with network disabled,
`archive --format=zip`, and `--no-scripts --no-plugins --no-interaction`.
The fixed archive filename is a probe name, not a release version.

The first two staging attempts encountered Python 3.13 temporary-directory ACL
restrictions before any archive ran. An isolated directory with inherited
permissions under ignored `build/` allowed the controlled operation. No permission
escalation, root Git mutation or network access was used.

## Red and correction

The pre-correction archive command exited 0 but contained 32 files, including
synthetic `vendor/probe.txt`, `build/probe.txt`, `.env` and `composer.lock`.
Its SHA256 was
`aa4da62fa2704c1c1990d4ba04bb0b605246eb8d12d8308e0e104405e687e0c3`.
Internal/docs/test/style markers were already excluded, and the new PHP migration
was present. The output therefore reproduced a working-tree archive defect;
command success alone was not a distribution pass.

Composer's installed archive implementation reads export-ignore/archive rules,
not `.gitignore` as an exclusion policy. The coordinator corrected root
`.gitattributes` to exclude dependencies, build/tool output, coverage, environment
files, the development lockfile and local IDE/cache material. All `.env.*` files
are excluded; no `.env.example` is shipped or promised by this package.
The reviewer changed no runtime source or exclusion rules.

## Green evidence

A fresh controlled stage used the corrected root attributes and current runtime
files. Composer exited 0 and produced exactly **28 files**. Inspection found no
synthetic marker from `.docs/`, `.agents/`, `.claude/`, `docs/`, `tests/`,
`examples/`, `.github/`, `vendor/`, `build/`, coverage, IDE/cache directories,
`pint.json`, `.env`, `.env.production`, `composer.lock` or `.phpstan.cache`.
The scratch `.git/` directory was also absent.

The archive contains
`database/migrations/2026_06_08_000000_create_sms_dispatches_table.php` and no
migration stub. Every archived file's SHA256 matched the corresponding current
root file at verification time. The root `.gitattributes` SHA256 used was
`e063a8b8fe9c14d0a4e3eeb95a680806da612544144b7581f22f974a42bc3576`.

- Retained archive: `build/artifact-review-20261111-289d5acb608a455da0a1d88e243653c0/archives/textsms-nov11-candidate.zip`
- Archive size: 56720 bytes.
- Archive SHA256: `bfed586d2ae40c8d47389c8a303c2a19f2b6fc304c23d5591448a87c9cfccd61`.
- Composer PHAR SHA256: `dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028`.
- Machine-readable inventory: `build/artifact-review-20261111-289d5acb608a455da0a1d88e243653c0/final-inventory.json`.

The pre-existing PHP Imagick startup warning appeared. Composer could not derive
a version from the uncommitted scratch repository and used its default root
version; that warning is not evidence of an actual 1.0.0 tag.

## Direct-checkout follow-up

Coordinator follow-up: a direct archive from the full checkout was attempted
after the corrected exclusions. It exited1 because recursive traversal could
not read an earlier ignored scratch directory,
`build/artifact-review-20261111-9m3y_trl`, created by the failed temporary-directory
probe. No direct-checkout archive pass is claimed. The controlled stage above
avoids that local permission problem and remains the accepted candidate
contents evidence; the clean committed/tagged distribution is a separate gate.

## Inventory of the accepted controlled archive

| File | SHA256 |
|---|---|
| `LICENSE` | `754832c1b2384ed32ed0549a0282e88908eec1dc8a3679ec1a4fd9c543e9c11e` |
| `README.md` | `cfdb9b8600621194fcb7678e30fb1a2ba735854d006d29334918020289980e1c` |
| `composer.json` | `d61085a48e5d7156f125cc7645e4fa0837438e84bb807c56cc535528b634c8fb` |
| `config/textsms.php` | `6a8e6abb10bfa0dcbd44964c6d0235d913d5b9d0289d4c104b6b74c15716e7ba` |
| `database/migrations/2026_06_08_000000_create_sms_dispatches_table.php` | `1e83dd1f9b031b3c358beff6f3d0371de070a5635cf013a3e87d3efef7861cbb` |
| `routes/webhooks.php` | `a4c6efa9e90837fed72303ef3c6e6f207c9a723ad36eae517c9dd76e3b4063a4` |
| `src/Console/Commands/BalanceCommand.php` | `5188079dbb1f8bff7730b5e03d148b66c5d7dc8012ddf7d06804c46e49ae8b54` |
| `src/Console/Commands/ShowDispatchCommand.php` | `024ca041fe3a872dcfdca32265a05b63d7a3d3835160f178e80791f024d7f846` |
| `src/Console/Commands/TestSendCommand.php` | `48b377c4c33846108e41d1db1e33675738c3ea58d4913db44da9b6ec99161966` |
| `src/Contracts/PhoneNumberNormalizer.php` | `fbd142bbd0a5f298331e1ea3a5744b9c1e111dfba8f99bc169e6b4743de89134` |
| `src/Contracts/SmsDeliveryReportProcessor.php` | `6abbcf81f1402106498f7d9ef1823772e56773c2386ae5eaa7e90f6d1b53b397` |
| `src/Contracts/SmsSender.php` | `327622da66520b00273f8943b61ed2709497721d5924e487d89661c424b2f5cb` |
| `src/Events/SmsDeliveryReported.php` | `3be4ff8be32fb3c2e70342d024804065104a99e38a57c57b422d0de63646feea` |
| `src/Events/SmsDispatchAccepted.php` | `3bc36229650735350ad6ec21e7355914d930e0e2656e05f55ec29f1e9c8b8d9d` |
| `src/Events/SmsDispatchFailed.php` | `3f83d4b8dae7b2ee7c63f6ceb5cb0b527f7d7fecb77610466ac24c972dc96f56` |
| `src/Exceptions/SmsDispatchException.php` | `b788fb6a06d88772aa24549cde4fb2b7af413dce45cfcc51dee2ce18c1aed589` |
| `src/Exceptions/SmsProviderException.php` | `2f7ed5fdcd798c6c048c2360d94b0970a96fa33608f5962b1780572b0808595a` |
| `src/Gateways/LogOnlySmsGateway.php` | `ba9b90ebf12d616dcb3fe2cc8c85be3760602222f8987e5f36720a86df549393` |
| `src/Gateways/TextSmsGateway.php` | `aa56e364662b2e6be45a3ec6bb39e1e93a46bee4e3888cc78b935846eb57fa8b` |
| `src/Http/Controllers/TextSmsCallbackController.php` | `cb9affc196c036129deec84abb2780ee9ac78abe7c4c24f916b68059a45839bf` |
| `src/Http/Middleware/VerifyTextSmsCallback.php` | `3f01897f955a048c7a539915587c638ae6f4cf08674cba2cafd1f92cac8df3da` |
| `src/Messages/SmsMessage.php` | `a7465044b07cbce474bb22850016b5cdbc72982abb68c4b9134e0a65504b03ae` |
| `src/Models/SmsDispatch.php` | `5255025e50f4e72a0679d68a919f6d5f2232564f995500fbf6a0f6e3df771ce0` |
| `src/Notifications/Channels/SmsChannel.php` | `7b2e892cc120637c6bd3f2e41253be0d9717c4507ea204501fca6401a5ebb9f1` |
| `src/PhoneNumbers/ChainedNormalizer.php` | `082373d8bff9c019479f97c972a4face0f240d326c5aaf3dd81346bf2c08c37b` |
| `src/PhoneNumbers/KenyanE164Normalizer.php` | `cf352c2426de751b243f63825aee47a1f0e66534926d7dd3b75f7338cfae2264` |
| `src/Testing/FakeSmsGateway.php` | `0f19355a6e88d580b778a8c9de0fced41e399f4cbafaf5b8c1ef40c24a24dc83` |
| `src/TextSmsNotificationServiceProvider.php` | `4b3681f90d82460867e9916afaa37528ef41876cae7d4f9eab22d6dc92539e50` |

## Limits and final source guard review

This is an actual Composer ZIP from a controlled copy of the candidate runtime
files, with adversarial exclusion markers. It is not an archive of root HEAD or
a final tracked/tagged release tree. Git-hosted distribution normally contains
tracked files, whereas local Composer archive walks the working tree; ignored
local material therefore needed explicit archive exclusions. The exact release
commit/archive and fresh installation remain separate release gates. Retained
probe files under ignored `build/` are verification artifacts, not package content.

The final gateway capability booleans are computed before exception type narrowing
and then used with short-circuit checks. Removed Guzzle 8 methods are never called
merely because an exception has a Guzzle type. The service-provider callback
accepts the HTTP kernel contract and calls Foundation-specific priority methods
only for a Foundation kernel. These are minimal compatibility guards; no new
retry policy or middleware framework was introduced. No additional source-review
blocker was found. Prior independent Guzzle 7/8 runtime probes are recorded in
`2026-11-11-independent-source-review.md`; root owns the final integrated suite.
