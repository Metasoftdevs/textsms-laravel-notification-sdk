# T-04 remainder — Credentials and truthful balance outcomes

**Date:** 2026-11-11, Africa/Nairobi. **Owner:** Codex.
**Status:** implemented and accepted within scope by independent review;
final integrated release checks follow in the November 11 execution record.

## Plan and result

Missing API key or partner ID must fail outbound operations before preparing
rows or contacting TextSMS. Validate input normally, retain empty-bulk no-op
and keep passive callbacks usable without outbound credentials. Guards live
on outbound operations, not the constructor, and expose field names only.

Balance now rejects non-2xx HTTP, empty/list/malformed JSON, invalid/non-200
explicit provider codes and populated error details. Provider code, description
and structured errors stay available on the package exception. The existing
command then prints failure and exits unsuccessfully. Valid free-form JSON
objects, including zero credit, remain unchanged. Balance retries are separate
and unchanged. No new balance DTO/schema or retry framework was added.

The bundled Postman balance capture is incomplete and does not establish a
required `credit` success field. An early test draft required invented credit
shapes and used an invalid fake object body; it was corrected before the
proper regression run. No live balance success contract is claimed.

## Actual evidence

| Check, November 11 | Result |
|---|---|
| Corrected regression run before fix | 19 failed, 7 passed; 34 assertions; seed 1794379089 |
| Credential/balance plus existing retry focused green | 67 passed, 252 assertions; seed 1794379198 |
| After Guzzle 7/8 retry compatibility correction | 76 passed, 270 assertions; seed 1794381788 |
| Independent review | `nov05_gateway_crosscheck` accepted |
| Independent execution | Eight synthetic/no-network credential/balance controls passed; no source edits |

Tests cover missing credentials for all outbound methods with no row/HTTP,
credential-free report recording, HTTP failures even with success-shaped
bodies, preserved provider errors, malformed shapes, zero/numeric data,
free-form payloads and real command failure exit. Synthetic fixtures prove
SDK handling, not the account's provider response contract.

## Failure, recovery and limits

Credential failure explicitly states no request was sent. Balance exceptions
do not describe an SMS send. Outbound uncertainty, preparation/result-recording
recovery and approved D2 remain intact. The next real account evidence is an
authorized balance lookup and sanitized capture under T-07. No provider call,
credential change, commit or global toolchain update occurred in this packet.
