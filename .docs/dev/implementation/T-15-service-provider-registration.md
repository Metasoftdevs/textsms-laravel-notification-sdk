# T-15 - Lazy Notification Channel Registration

**Date:** 2026-11-02, Africa/Nairobi. **Status:** accepted after integrated gate and independent source review. **Owner:** Codex.

## Plan and contract

Register the existing `sms` driver with `callAfterResolving(ChannelManager::class, ...)` instead of creating the manager during provider boot. It must work whether the manager is first resolved later or already exists when the provider boots. The driver factory resolves the current `SmsSender` binding when creating a channel; Laravel's existing driver caching remains in effect until `forgetDrivers()` is called. Fake installation cache invalidation belongs to T-14.

Remove the inert `HttpKernel` block/import; retain the router middleware alias. Cover lazy resolution, real ChannelManager dispatch through the currently bound sender, and an already-resolved manager. Write tests before the change, then focused/integrated checks and independent review. No route, sender-construction, migration-publication or retry policy changes are included.

## Implementation and verification

The package now defers driver registration through the framework hook; the hook also executes immediately if the manager was already resolved. The driver factory continues to resolve the bound sender when the channel is created. Tests invoke actual `ChannelManager::sendNow()` and assert sender interception on both paths. The old kernel branch had no effect and is removed; the router alias remains unchanged.

| Check, 2026-11-02 | Actual result |
|---|---|
| Regressions before fix | 1 failed (eager manager resolution), 2 passed, 5 assertions; seed 1793644041 |
| Integrated Pest suite | 189 passed, 1032 assertions; seed 1793644071 |
| PHPStan level 8 | No errors |
| Pint owned files and full `--test` | Passed |
| Composer strict validation / diff whitespace | Passed |

Independent read-only review found no blocker in the hook, already-resolved behavior, driver factory, middleware preservation or test relevance. Reviewer did not run tests. No provider calls, commits, dependency or migration changes occurred.

**Intent/invariant:** package boot does not create a notification manager just to register a driver. Both manager-resolution paths can construct the same existing SMS channel.

**Recovery/limits:** Laravel still caches created channels. Rebinding `SmsSender` alone does not replace an existing channel; explicitly forget drivers when swapping. The automatic fake-install path is T-14, not established here. A failure to resolve a sender/logger remains a normal container error.

**Artifacts/reviewer focus:** service-provider change, `tests/Feature/ChannelRegistrationTest.php` and CHANGELOG. T-04 will independently modify sender construction; retain this lazy registration when integrating it.
