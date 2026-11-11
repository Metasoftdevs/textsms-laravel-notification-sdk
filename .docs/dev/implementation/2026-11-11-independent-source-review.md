# Independent source review and synthetic probes

**Date:** 2026-11-11, Africa/Nairobi. **Reviewer:** `nov05_gateway_crosscheck`.
**Disposition:** T-04 remainder, T-13, T-12 and T-14 accepted within their
reviewed source scopes. The Guzzle 7/8 D2 adaptation is accepted within the
approved retry policy. This is not release or complete compatibility acceptance.

## Scope and source findings

The review covered the current gateway, sender/processor contracts, model,
service provider, creation migration, fake/log senders, their focused tests,
installed framework/transport internals and affected public guidance. Root and
the implementation agents own their test-first chronology and full-suite gates;
the executions below are this reviewer's independent evidence.

- **T-04:** outbound API-key/partner-ID guards execute before rows or HTTP,
  without preventing passive callbacks or changing empty bulk calls. Balance
  rejects HTTP errors, malformed/empty/list payloads, invalid or non-200 codes
  and populated errors; structured provider details remain available. Valid
  free-form payloads are preserved without inventing a credit schema. The
  bundled Postman balance capture contains only an incomplete opening brace;
  it does not establish a live success schema.
- **T-13:** all three writers use native `notifiable()->associate()`. This
  respects aliases and the owner's actual primary key. Tests inspect stored
  values, `whereMorphedTo()` and relation reloads, and restore global morph
  settings. SQLite's acceptance of string keys in an integer-affinity column
  does not prove UUID/ULID schema compatibility on another engine.
- **T-12:** directory-based `publishesMigrations()` and a discoverable PHP
  migration replace manual boot-time naming and the stub. Fresh status storage
  is a string of length 16; the attempt default is 1. Existing installations
  need an application-owned forward migration preserving data/indexes/keys and
  accounting for their actual enum or CHECK restrictions. Publication is once
  per installation; no repeat-safety mechanism is claimed.
- **T-14:** complete input preflight precedes configured-connection transactions.
  Cancelled or throwing saves cannot produce fake success. Installation replaces
  both contracts and forgets already-cached notification drivers without eager
  manager resolution. Sticky simulated failures save failed rows and publish
  failures before throwing the configured exception; only accepted sends enter
  `sent`. Immediate listener/reporter failures and log-only logger failures are
  contained. Callback methods remain passive no-ops.
- **D2/Guzzle compatibility:** each send client owns fresh transfer-stat state.
  A Laravel connection exception plus response-free transfer statistics carrying
  strict integer cURL 6/7 permits the one retry. Ambiguous or response-bearing
  statistics block legacy fallback. Guzzle 7 fallback methods are callable-
  guarded, avoiding the removed exception-context APIs in Guzzle 8. Laravel's
  native `on_stats` wrapper retains its statistics after the local void callback.
  Received responses, including redirects, do not cause package retries.

## Independent executions

All provider interactions below were synthetic and stray requests were
prohibited. Standalone Testbench probes used an in-memory database and
initialized PHPUnit configuration for clean teardown. No permanent probe or
test file was created. The initial standalone harness lacked that configuration
and failed at teardown; the reported completed probes are subsequent exit-0 runs.

| Probe | Observed result |
|---|---|
| Original cached-channel and morph defects | Before the respective fixes: fake installation left the cached real channel active, with zero fake records and one faked HTTP request; enforced morph mapping stored the class name and `whereMorphedTo()` matched zero rows. |
| Balance and credential boundary | Eight controls passed: zero/free-form success; retained refusal details; malformed code, HTML, list and HTTP-error rejection; missing key contacted no handler. |
| Native migration publication | Two isolated one-time CLI publications passed. Disabled date rewriting preserved the filename; enabled rewriting changed it. Each produced one migration. Verified temporary targets were removed. |
| Corrected doubles | Cached-channel installation intercepted with one fake record and zero HTTP. A two-row failed fake batch retained its exact exception and published both events despite failing listeners/reporting. A two-row log-only batch returned sent rows and published both despite logger/listener/reporter failures. |
| Guzzle 7 statistics and isolation | Eight controls passed on PHP 8.2.12 / Laravel 12.61.1 / Guzzle 7.11.1: cURL 6/7 retry; timeout, empty reply, string/missing codes and received-response statistics do not; prior evidence does not leak into a later send on the same gateway. |
| Guzzle 8 statistics and isolation | The corresponding eight controls passed on PHP 8.3.13 / Laravel 13.42.0 / Guzzle 8.3.1, using the isolated graph's dependencies and the current root gateway class. Two further context-free Guzzle connection/request causes each stopped after one attempt without a removed-method error or TypeError. |
| Candidate workflow structure | YAML parsing produced 12 rows: Laravel 12 on PHP 8.2/8.3/8.4 and Laravel 13 on PHP 8.3/8.4/8.5, each at lowest/stable. This is configuration evidence, not execution of those rows. |

The public example/upgrade/queue/contributor/checklist edits were also reviewed.
They now describe passive callbacks, standard queued notifications, uncertain
outcomes, publish-once migrations and evidence-dependent runtime/database claims.
The documentation writer corrected fixture-provenance and toolchain wording.

## Limits and next acceptance boundary

The package cap applies to Laravel retry attempts, not every on-wire transport
action. Installed Guzzle source has lower-level recovery behavior; neither these
probes nor client references establish provider idempotency or exactly-once sends.
Host outer transactions, after-commit callbacks and deferred queue work retain
their documented limits. Native morph association does not upgrade historical
aliases or database column types.

Full current-tree suites, actual lowest/stable matrix executions, audits and
fresh-application checks belong to their coordinator/compatibility records.
Real database enforcement and upgrades, the exact release artifact, remote CI on
the candidate commit and authorized provider captures remain separate evidence.
The reviewer changed no runtime source, root dependencies, lock/vendor graph,
credentials, Git history or remote state. This record is the only permanent file
written by this review packet.
