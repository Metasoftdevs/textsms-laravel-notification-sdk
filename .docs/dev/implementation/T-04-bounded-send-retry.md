# T-04 bounded slice — One proven connection retry

**Date:** 2026-11-09, Africa/Nairobi. **Owner:** Codex.
**Status:** scoped D2 implementation accepted by independent source review;
integrated runtime gate passed. Original T-04 is only partially complete.

## Problem, objective and approved boundary

The common HTTP client retried broadly, including responses and ambiguous
failures that could follow a real SMS send. The owner approved one additional
send attempt only for proven DNS/connect failures, cURL 6/7. All other send
errors retain existing exception/diagnostic behavior. Do not add a retry
policy abstraction or broaden this packet to credentials/balance work.

## Implementation

`send()` and `sendBulk()` select the send-specific HTTP configuration.
Laravel allows two total attempts, 200ms apart. The predicate requires a
Laravel `ConnectionException` with a direct structured Guzzle connection or
no-response request cause and integer `errno` 6/7. Error text, missing
context, string codes, timeouts, empty replies and HTTP responses do not
qualify. Send redirects are disabled to prevent hidden forwarding before a
later connection error. Balance retains its existing client behavior.

The send preparation/result recording and diagnostic paths are unchanged.
One logical row per item remains; the HTTP retry does not create another
dispatch row. Existing broad-retry count expectations were updated.

## Review correction and actual verification, November 9

| Check | Result |
|---|---|
| Initial new retry tests against old behavior | 20 failed, 13 passed; 98 assertions; seed 1794252259 |
| Combined focused green before redirect correction | 193 passed, 1211 assertions; seed 1794252326 |
| New redirect regression red | 4 failed, 4 passed; 20 assertions; seed 1794252677 |
| Final retry-focused green | 41 passed, 186 assertions; seed 1794252687 |
| Integrated final runtime suite | 281 passed, 2117 assertions; seed 1794253104 |
| PHPStan level 8 | No errors |

Independent reviewer `nov05_gateway_crosscheck` found that Laravel passes
null to the retry predicate for 3xx responses. A non-nullable predicate caused
a bulk `TypeError` and left rows pending. New single/bulk 301/302/307/308 tests
reproduced the bulk failure, then `?Throwable` fixed it at that predicate.
Redirects now remain unknown recorded failures, with one HTTP attempt.
The reviewer accepted the correction and independently ran six no-network
HTTP-client controls (301/302/307/308/422/500); these returned the original
status, redirects disabled and one handler invocation each. They did not
independently execute the DB integration suite.

## Limits and remaining T-04 work

The cap applies to package/Laravel retry attempts, not an absolute count of
on-wire transmissions. Installed Guzzle source contains lower-level recovery
paths (including failed-rewind handling); no live reproduction or custom
transport policy is claimed. Provider idempotency/exactly-once sending remains
unproven. Uncertain final outcomes remain unsafe to resend automatically.

Original T-04 credential preflight and balance response classification remain
pending separately. They are not silently marked implemented by this minimal
D2 slice. No provider request, credential change, commit or deployment occurred.
