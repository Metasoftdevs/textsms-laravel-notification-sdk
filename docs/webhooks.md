# Delivery-report webhooks

The SDK accepts POST callbacks and stores the sanitized latest payload in
the matched dispatch's `dlr_payload`. Reports are informational only:
submission state, identifiers, counters and timestamps (including
`updated_at`) stay unchanged. No SDK event, Eloquent observer or application
action runs, including retrieval hooks. No delivery outcome is classified.

Repeated or reordered reports replace that one payload; this is neither
delivery history nor an authoritative delivery outcome. Every explicit
send already creates a separate dispatch row. Callbacks do not resend.

## Endpoint and secret callback URL

```text
POST https://yourapp.com/api/v1/sms/textsms/dlr?token=YOUR_SECRET
```

Use the existing environment-configured secret:

```env
TEXTSMS_DLR_SECRET=your-long-random-secret
TEXTSMS_WEBHOOK_RATE_LIMIT=600
TEXTSMS_WEBHOOK_PREFIX=api/v1/sms
```

Generate it with `php -r "echo bin2hex(random_bytes(32));"`. Use the same
value in `.env` and the registered URL's `token` parameter. No second URL key
is needed. The SDK also accepts `X-TextSMS-Token`; when present that header
takes precedence over the query token. Prefer the header if the provider
supports it, to avoid secrets in access-log URLs.

`VerifyTextSmsCallback` uses constant-time comparison. Missing, wrong or
non-string tokens receive 401; an unconfigured secret receives 500. Denial
logs include query keys and the URL without its query string, never the
presented token. Host proxies/access logs need their own redaction.

## One authenticated rate limit

The package route applies Laravel's named `textsms-dlr` limiter after
authentication. The default is **600 authenticated callbacks per minute**,
shared across source IPs for this application's TextSMS integration.
`TEXTSMS_WEBHOOK_RATE_LIMIT` accepts a positive integer; values below 1 use 1.
Excess requests receive Laravel's 429 response with `Retry-After`.
Invalid tokens neither consume this allowance nor receive 429 when it is
exhausted. Authenticated malformed and unmatched callbacks consume it.

The limiter uses Laravel's configured rate-limiter cache. Share that cache
across application servers if the allowance must apply across them. It does
not replace perimeter controls for unauthenticated traffic.

`textsms.webhook.middleware` defaults to `[]` for optional additional host
middleware. Authentication and the named limiter are on the package route.
**Upgrade published configs:** remove the old `throttle:60,1` if you intend
to use only the new named limit; otherwise it remains an additional
restriction. Update previously published route copies to use
`throttle:textsms-dlr` as well as `verify.textsms.callback`.

## Payload and response contract

The controller strips the top-level `token` from query/body input.
`messageid` (or `messageID`) must be an integer or non-blank string;
`0` and `"0"` are valid. `status` (or `dlrstatus`) may be absent or a
string/number. Arrays, objects and booleans are invalid status values.
These are SDK input rules; no callback outcome is inferred.

| Scenario | Response |
|---|---|
| Authenticated, within limit, valid payload, matched ID | 200 with `{status: 200, message: 'OK'}` after recording the payload |
| Valid payload, unmatched ID | 200; metadata-only info log records the miss |
| Missing/null/blank message ID | 422, `Missing messageid in payload` |
| Invalid message ID type | 422, `Invalid messageid in payload` |
| Invalid status type | 422, `Invalid status in payload` |
| Missing/wrong/malformed token | 401, `Unauthorized` |
| Secret unconfigured | 500, `Server misconfiguration` |
| Authenticated allowance exhausted | Laravel 429 with `Retry-After` |
| Recording fails | Exception follows the host handler, normally 500; no success acknowledgement |

Shape rejections produce one warning listing field names only. The default
recorder suppresses model events so application observers cannot act on,
veto or interrupt the informational write. Storage/encoding failures
propagate. No delivery listener is invoked.

A callback arriving before its provider ID is saved may be acknowledged as
unmatched. No repair/polling job is shipped or required for passive storage.
Direct callers of `recordDeliveryReport()` must sanitize and validate input
themselves; the HTTP boundary supplies that guarantee.

## Provider evidence

Extraction history reports push callbacks in QPIMS, but this checkout has
no sanitized live fixtures confirming TextSMS's current callback method,
payload, registration, header support, signing or retry behavior. Confirm
these for the account before registering the endpoint. The bundled PDF's
`/api/services/getdlr/` describes lookup, not proof of push support.
No polling command or HMAC verification is shipped.

An account owner can authorize a billable test and capture sanitized
method/content-type/field/status/response evidence. Exclude credentials,
recipients and message content from shared fixtures. Synthetic tests
prove SDK behavior, not the live provider contract.

## Custom route registration

Change `TEXTSMS_WEBHOOK_PREFIX` to move the endpoint, or set
`TEXTSMS_WEBHOOK_ENABLED=false` and publish/include a customized routes file:

```bash
php artisan vendor:publish --tag=textsms-routes
```

It lands at `routes/textsms-webhooks.php`. Keep authentication before the
named limiter when adapting the route.

## Older secret-bearing installations

Earlier versions retained the query token in report payloads and denied
request logs. Rotate `TEXTSMS_DLR_SECRET` wherever callbacks were received
and update registration. Decide whether to scrub old `dlr_payload.token`
values and log retention, including reverse-proxy/access logs. See
[operations](operations.md). Code deployment does not rotate secrets or
delete existing data.
