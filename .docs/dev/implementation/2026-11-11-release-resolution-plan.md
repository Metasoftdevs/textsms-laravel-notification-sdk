# Release issue resolution plan

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex.
**Status:** implementation and independent source review complete; actual final
verification and external release gates are tracked in
[execution status](2026-11-11-execution-status.md). Latest owner instruction: resolve the
pending release issues simply, accurately and with documentation; outline
resolutions first. `.docs/` and `.agents/` are approved for eventual commits.
`.claude/` is conditional on absence of personal/local tool permissions.

## Resolutions and ownership

1. Root is the sole common-gateway/provider/migration source writer for T-04
   credential/balance handling, T-13 relation association and T-12 publishing.
   After T-13 completed, the documentation agent received exclusive T-14
   ownership of Fake/LogOnly and their two tests, with no overlapping writers.
   Use native Laravel boundaries and regression tests
   before fixes, independent review, then the integrated gate. Preserve D1-D4.
2. Use required-credential preflight only for outbound operations. Balance
   must reject HTTP/provider/malformed responses rather than claim success.
   Morph relations use Eloquent association. Fresh schema uses status string
   and attempt default1; document publish once and account for existing tables.
3. A documentation agent owns only the public example/upgrade/queue/contributing
   guidance and publication checklist. Remove unsafe recipes, do not add new
   queue/retry/event frameworks. Root owns README, CHANGELOG and status records.
4. Read-only agents verify provider evidence, compatibility/runtime/toolchain
   feasibility, current/history privacy and local `.claude` settings. Redact
   current recipient references; no unapproved Git history rewrite.
5. Investigate and validate Laravel12/13 support with actual resolved graphs
   and appropriate toolchain. Do not widen support from version arithmetic.
   Run fresh install/artifact/database checks where the environment permits.
   A locally isolated maintained Composer tool is preferred over changing the
   user's global installation.

## Evidence and authorization boundaries

The review's absolute 'no SMS ever sent' and 'CI never run' claims require
qualification to absent candidate evidence unless remote/live facts establish
them. Local schema/morph/balance/fake issues are source-verifiable. Laravel13
is a support-window decision; user requests resolving the listed issue, so
test dual-major support instead of promising it unverified.

Live tests need a consenting recipient and identified authorized credentials;
an early async question requests those while local work proceeds. No API key
is requested in chat. History rewriting, pushing, remote workflow triggering,
tagging or publication is not inferred from preparing fixes. Record exact
remaining external gates and request only necessary concrete approvals after
preparation. Do not mark blocked evidence as passed.

Existing uncommitted work stays intact. Every packet records actual red/green
results and source review separately. Approved passive delivery reports stay
informational; no poller, delivered precedence, callback application actions,
resend counter/history framework or general retry-policy abstraction is added.
