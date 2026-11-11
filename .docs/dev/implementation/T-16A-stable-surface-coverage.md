# T-16A - Stable public-surface coverage

**Date:** 2026-11-05, Africa/Nairobi. **Status:** accepted within this bounded slice after independent review and final integrated gate. **Owner:** Codex. This is a bounded T-16 slice, not acceptance of the full coverage packet.

## Plan and contract

Cover already implemented normalizer composition, service-provider
bindings/processor mismatch and supported overrides, middleware/publication
groups, console command registration and stable balance/test/show command
outcomes. Verify route-disable and custom-prefix behavior on applications
configured before package boot; changing configuration after routes loaded
does not establish conditional loading. Use actual Testbench application
integration and mocked senders rather than external requests.

This work adds conformance tests without changing runtime code, so no
pre-existing red run is claimed. It excludes pending report/retry/double
behavior, migration publish semantics (D5), absent polling and unsupported
compatibility rows. The console-negative boot branch and any new poller
need later T-16 coverage; the complete T-16 packet remains open.

Run focused coverage, format every new untracked PHP test explicitly,
independent review, and the integrated gate. Record only actual executions.
No provider calls, dependency changes, commits or schema modifications.

## Actual implementation and review

Five new test files add 20 cases: seven normalizer-composition cases,
five service-provider contract cases, two real application boot-route cases
and six stable Artisan outcomes. The boot cases configure the application
before provider boot. Console operations use a mocked sender and synthetic
dispatches; stray outbound HTTP is prohibited. These cases do not prove
provider behavior or supported dependency graphs.

Initial focused run: **20 passed, 92 assertions**, seed 1793871443. The
integrated suite then passed **229 tests, 1243 assertions**, seed
1793871468. PHPStan level 8, Pint, strict Composer validation and whitespace
checks passed. Independent review identified one test weakness: the
dispatch-show case asserted JSON section headings but not their values.
Value substring assertions were added; the final focused Artisan file
passed **6 tests, 35 assertions**, seed 1793871594. Reviewer accepted the
bounded slice after this improvement. Final stable-tree gate: **229 tests,
1245 assertions**, seed 1793871865; PHPStan level 8, Pint, strict Composer
validation and diff whitespace all pass. That run includes the final
JSON-value assertions and all prior corrections.

**Intent/invariants:** verify documented stable behavior through actual
composition, container, route and console boundaries. Tests distinguish
normalizer decline/match/short-circuit, custom sender versus separately
bound processor, configured route loading and command exit/output contracts.

**Limits/reviewer focus:** full T-16 remains open. No inference is made
about D5 repeat publishing, T-06 defaults, negative console boot, pending
doubles or polling. Runtime code was not changed and no artificial red
history is claimed. Read-only review and root-run gates are separate
evidence. Artifacts are the five new stable-surface test files.
