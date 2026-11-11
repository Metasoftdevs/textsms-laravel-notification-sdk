# T-06 — Authenticated configurable callback limiter

**Date:** 2026-11-09, Africa/Nairobi. **Owner:** Codex.
**Status:** accepted within approved scope after independent source/documentation
reviews and the integrated local gate.

## Problem, objective and approved boundary

The old default `throttle:60,1` was arbitrary, could split allowances by IP
and ran before callback authentication. Tests normally removed that middleware.
The owner approved one Laravel limiter, default 600 authenticated callbacks
per minute, configurable through env. Reuse the existing callback URL secret.

## Implementation

`TextSmsNotificationServiceProvider` registers `textsms-dlr` with one stable
integration key and `Limit::perMinute()`. Config reads
`TEXTSMS_WEBHOOK_RATE_LIMIT` into `webhook.rate_limit_per_minute` (default
600, effective minimum 1). The package route explicitly applies authentication
and the named limiter. Extra host middleware defaults to an empty array.

The service provider places `VerifyTextSmsCallback` before both framework
`ThrottleRequests` and `ThrottleRequestsWithRedis` in native HTTP-kernel
middleware priority. This preserves authentication-first behavior when
Laravel sorts route middleware, including routes with `SubstituteBindings`.
No new middleware/cache/rate-limit service or custom response is introduced.

`TEXTSMS_DLR_SECRET` already configures the requested URL key:
`?token=<secret>`. `X-TextSMS-Token` remains supported with header precedence.
Existing constant-time authentication and token sanitization are retained.
No second path/query credential or automatic callback registration is added.

## Actual verification, November 9

| Check | Result |
|---|---|
| Correct new HTTP regression red | 4 failed, 14 assertions; seed 1794252549 |
| Focused HTTP green | 4 passed, 623 assertions; seed 1794252574 |
| Integrated final runtime suite | 281 passed, 2117 assertions; seed 1794253104 |
| PHPStan level 8 | No errors |
| Independent review | `nov05_integration_crosscheck` accepted; source/test review only, no independent execution |

An initial test helper collided with PHPUnit's final `callback()` method.
It was renamed before the proper red run; that fatal is not regression
evidence. Tests restore real package webhook defaults, exercise 600 successes
then 429 with `Retry-After`, a configured shared allowance across source IPs,
401 before/after bucket exhaustion with priority sorting, and env-loaded URL
secret/rate configuration. All provider HTTP requests are prohibited.

## Configuration upgrade and operational limits

Previously published configs can retain `webhook.middleware=['throttle:60,1']`.
Laravel preserves that explicit custom setting, so remove/update it to use
only the new limiter. Published route copies also need the named limiter;
the package cannot rewrite host files. Public documentation includes both.

The configured rate-limiter cache must be shared across application servers
for one cross-server allowance. These tests execute the array cache; Redis
middleware ordering is source-reviewed, not Redis-backend tested. Live
provider volume/retry behavior is still T-07 evidence. Unauthenticated requests
are rejected first and do not consume this bucket; perimeter controls remain
application-owned. No deployment/configuration or live-secret change occurred.
