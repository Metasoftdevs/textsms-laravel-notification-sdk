# T-18 - Laravel 12/13 compatibility verification

**Date:** 2026-11-11, Africa/Nairobi. **Status:** two corrected Laravel 13/PHP
8.3 suites verified; final source acceptance and the remaining matrix are open.
**Owner:** Codex compatibility agent. The coordinator owns the subsequent
capability-guard correction and final snapshot checks. See the current
[execution status](2026-11-11-execution-status.md) for those final results.

## Planned verification and boundary

Use isolated temporary projects for every dependency graph. Preserve the
root Composer manifest, lock/vendor state and workflows. Snapshot the completed
source, tests, config, routes and migrations with a checksum inventory; remove
obsolete migration files only inside verified scratch directories.

Refresh the already installed Laravel 13/PHP 8.3 stable and lowest graphs,
then run their full Pest suites, PHPStan and current lockfile audits. Resolve
the candidate's actual dependency constraints per framework major and PHP
runtime. Acquire portable PHP 8.4/8.5 from official Windows release metadata,
verify each archive's SHA256 before execution, and attempt the remaining
declared matrix rows without replacing globally installed tools.

Retain the red baseline: the initial Laravel 13/Guzzle 8 graph exposed removed
exception-context APIs and incompatible exception fixtures. The coordinator
has since implemented native transfer-stat evidence and refreshed fixtures.
The actual corrected runs, selected versions, seeds, checksums and limitations
are recorded below. No live SMS, application credentials, root Git writes,
remote CI trigger or publication belongs to this task.

## Actual resolved graphs

Both graphs were installed in isolated temporary projects with official
Composer 2.11.2 and the actual Herd PHP 8.3.13 runtime. The coordinator's
candidate constraints were copied into each project, adding only the
framework-major selection `laravel/framework:13.*` used by the matrix.
Re-resolution after the candidate constraint refresh changed no package
versions. Root Composer files, vendor dependencies and workflows were not
modified by this task.

| Dependency | Prefer stable | Prefer lowest + stable |
|---|---|---|
| Laravel framework | 13.42.0 | 13.30.0 |
| Orchestra Testbench | 11.3.0 | 11.0.0 |
| Pest | 4.7.8 | 4.4.1 |
| Pest Laravel plugin | 4.1.0 | 4.1.0 |
| PHPUnit | 12.5.33 | 12.5.12 |
| Guzzle | 8.3.1 | 7.15.2 |
| Larastan | 3.14.0 | 3.9.3 |
| PHPStan | 2.3.5 | 2.1.32 |

"Lowest" means the graph actually resolved by current Composer under its
default advisory policy. Laravel 13.0 was not executed. No advisory or
platform override was used to force installation.

## Source snapshot and completed checks

The corrected runs below used the same 49-file inventory of source, tests,
configuration, routes and discoverable migrations. Inventory SHA256:
`a52a0d8b0d8564c71e09bf4f62db2076801491ae18be5476f4a8795ace5b9481`.
The obsolete migration stub was removed only from verified scratch paths.
This is a historical snapshot: it predates the coordinator's additional
provider-kernel regression and final capability-guard change.

| Check, 2026-11-11 | Actual result |
|---|---|
| Initial stable Laravel 13 baseline, before transport compatibility fixes | 305 passed, 20 failed, 2182 assertions; seed 1794379602 |
| Earlier lowest-graph retry/lifecycle/credential comparison | 122 passed, 663 assertions; seed 1794380188 |
| Corrected full stable suite | 380 passed, 2503 assertions; seed 1794382318; 39.25 s |
| Corrected full lowest suite | 380 passed, 2503 assertions; seed 1794382322; 83.53 s |
| Stable PHPStan level 8 | No errors |
| Lowest PHPStan level 8 | Two `function.alreadyNarrowedType` findings on cross-version capability guards |
| Candidate re-resolution, both graphs | No version changes; default Composer audits reported no security vulnerability advisories |
| Explicit stable lockfile audit | JSON contained no advisories or abandoned packages |

The red stable baseline exposed removed Guzzle 8 exception-context APIs and
changed exception constructors. Native request-local `TransferStats` and
compatible fixtures corrected the runtime failures. A notifiable fixture
also needed an actual primary-key attribute for Eloquent association rather
than only an overridden `getKey()` method.

The remaining lowest static-analysis findings arise because Guzzle 7 always
provides the guarded methods. A temporary `method_exists` probe retained
both warnings; a runtime public-method-list probe passed lowest PHPStan.
These probes changed only isolated files and restored them afterward.
The proposed capability-booleans-before-type-narrowing probe was not executed
by this agent. The coordinator owns the final native guard implementation,
its latest source snapshot and exact final results; those belong in the
[execution status](2026-11-11-execution-status.md) and its final amendment here.

## Toolchain and matrix rows not executed

Official Composer release metadata selected 2.11.2. The downloaded PHAR's
SHA256 matched the official checksum before execution:
`dd158c0afd41d15fac89e1b4e47b5d42911fe316c3687cd5edac98319b25d028`.
No global Composer update was performed.

Official [Windows PHP release metadata](https://windows.php.net/downloads/releases/releases.json)
provided portable NTS x64 builds. Both archives matched their advertised
SHA256 before extraction; both runtimes successfully executed `-v` and `-m`.

| Runtime actually executed | Archive release | Verified SHA256 |
|---|---|---|
| PHP 8.4.27 | 8.4.27-1 | `dbae154a0a89791f36c3d48e68bae797878333e7d6cb5721e74617de59b63ab7` |
| PHP 8.5.12 | 8.5.12-1 | `2a36a2edcb7a5b9252f638fd5e4cf84d7f6e066092654220dc0b63e5cf4e9723` |

No package suite was run with either portable runtime. This agent completed
only the two Laravel 13/PHP 8.3 rows above. Laravel 13/PHP 8.4 and 8.5,
and Laravel 12/PHP 8.2, 8.3 and 8.4, each at lowest and stable, remain
unverified by this task. Two Laravel 12 install attempts were launched in
sessions 83952 and 90824; their completion was not observed here. They are
not counted as installed, tested or audited rows.

An automatic approval-review service call failed because its usage limit
was reached. The requested next probe was not executed; this was not an
unsafe-action verdict. Work was subsequently bounded to documenting the
collected evidence while the coordinator continued final local checks.

## Reproducible artifacts and handoff

Temporary artifacts remain outside the repository:

- Stable project: `%LOCALAPPDATA%/Temp/textsms-nov11-compat-d5b754e9be6e4f3b9114b931f5dfefb8`.
- Lowest project: `%LOCALAPPDATA%/Temp/textsms-nov11-13lowest-a47e0bdc`.
- Portable runtimes: `%LOCALAPPDATA%/Temp/textsms-nov11-runtimes-7a2258c0/php84/php.exe` and `php85/php.exe`.

The two projects contain `source-snapshot.json`, Composer manifests/locks,
`candidate-resolution.log`, `candidate-pest.log` and `candidate-phpstan.log`.
The lowest project also contains the isolated capability-probe logs. The
stable directory contains the verified Composer PHAR and the scratch-only
snapshot helper. These are diagnostic artifacts, not release contents.

**Intent and invariant:** establish source compatibility through actual
framework/toolchain graphs; resolution alone is insufficient. Unknown send
outcomes retain their existing safety semantics across Guzzle versions.

**Reviewer focus and remaining work:** verify the final native capability
guards on both Guzzle majors, append the coordinator's exact final snapshot
results, and run the remaining declared matrix before claiming it green.
No latest-381-test pass or complete matrix acceptance is asserted here.

## Coordinator final-source follow-up

Both isolated projects were refreshed with the capability guards evaluated
before exception type narrowing and the additional contract-only kernel test.
The 49-file snapshot inventory SHA256 was
`643a878049500f46f3b2353211598b0446f64209cf6a7b06cbc226c61495f75c`.
Actual November 11 results:

| Graph, PHP8.3.13 | Final Pest | Final PHPStan level8 |
|---|---|---|
| Laravel13 stable / Guzzle8 | 381 passed,2504 assertions; seed1794386001;81.06s | No errors,exit0 |
| Laravel13 lowest / Guzzle7 | 381 passed,2504 assertions; seed1794386002;66.38s | No errors,exit0 |

PHPStan was run with `--debug --no-progress --memory-limit=1G`, avoiding
parallel worker startup delays. Earlier parallel commands were interrupted
and are not claimed as passes. No new ignore rule was added. After these
snapshots, Pint changed only import order in `ServiceProviderContractsTest`;
the production source and test assertions are unchanged. Root Pint across
all source/tests/config/routes/database/examples subsequently passed.

These two completed final rows supersede the pending final-source wording
above. The other ten matrix rows remain unverified; portable runtime startup
alone does not close them.

## Evidence limits

Local Windows/PDO SQLite matrix runs establish behavior on their actual
resolved graphs. They do not replace GitHub's Linux jobs on the exact release
commit, real-database smoke checks, fresh-application installation or live
provider captures. Those checks have separate owners and records. Composer
uses its default advisory policy; no insecure-dependency override is planned.
