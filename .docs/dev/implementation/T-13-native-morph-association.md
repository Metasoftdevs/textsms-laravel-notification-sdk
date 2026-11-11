# T-13 — Native notifiable association

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex.
**Status:** implemented and accepted within association scope by independent
review. Real database schema verification is tracked separately by T-21.

## Plan and implementation

All three senders previously assigned the notifiable's class and key by hand.
That ignored morph aliases and broke `whereMorphedTo()` queries. Replace those
assignments with the native `notifiable()->associate($notifiable)` relation.
The relation uses the mapped type and model's actual owner key. Update the
model key PHPDoc to `int|string|null`; do not invent key conversion machinery.

Native association also caches the owner relation. One old bulk test overrode
`getKey()` without setting a real key or providing a table; result refresh
then correctly tried to load that nonexistent owner. The fixture now uses a
persisted model/table and key73, rather than a fake identity that no relation
could restore. This is a fixture correction, not a runtime bypass.

## Actual evidence, November 11

- New regression tests: **18 failed, 18 assertions**, seed 1794379431.
- After association fix: **18 passed, 84 assertions**, seed 1794379460.
- Independent `nov05_gateway_crosscheck` accepted source/tests. Earlier clean
  standalone synthetic probes reproduced the alias mismatch and unmatched query.
- Cases cover real/fake/log senders, single/bulk calls, mapped integer and
  custom UUID/ULID primary keys, persisted aliases, owner keys, queries and
  relation reload. Both morph-map and require-map static state are restored.

SQLite's permissive integer-affinity column does not prove portable string
owner storage. Applications must select UUID/ULID morph columns before the
creation migration, or provide an explicit mixed-key schema. T-12 documents
that contract; engine-specific checks belong to T-21. Existing FQCN rows need
application-owned alias data migration if an application already installed
the earlier development version. No automatic data rewrite occurred.
