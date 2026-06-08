# DLR webhooks

The SDK exposes a single inbound HTTP surface: a delivery-report endpoint
the provider posts to whenever a previously dispatched SMS changes
delivery state.

## Default endpoint

```
POST {textsms.webhook.url_prefix}/textsms/dlr
```

With the default `'api/v1/sms'` prefix, that's
`https://yourapp.com/api/v1/sms/textsms/dlr`.

## Configuring on the TextSMS portal

Set the "Callback URL" on the portal to:

```
https://yourapp.com/api/v1/sms/textsms/dlr?token=YOUR_TEXTSMS_DLR_SECRET
```

Generate `YOUR_TEXTSMS_DLR_SECRET` once with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Put it in `.env` as `TEXTSMS_DLR_SECRET=...` and use the same value as
the `?token=...` query parameter on the portal callback URL.

## Auth model

The `VerifyTextSmsCallback` middleware sits in front of the endpoint and
performs:

1. Reads `config('textsms.providers.textsms.dlr_secret')`.
2. Returns **500** if the secret is unconfigured (forces explicit ops
   setup — fail loud, not silent).
3. Reads the supplied token from the `?token=...` query OR the
   `X-TextSMS-Token` header. The header form is preferred for ops who
   can configure custom callback headers.
4. Compares using `hash_equals()` for constant-time comparison.
5. Returns **401** on mismatch, with a PSR-3 warning log entry.

## Response contract

| Scenario | Response |
|---|---|
| Valid token, payload contains `messageid`, row matches | `200` with `{status: 200, message: 'OK'}`. Dispatch row updated, `SmsDeliveryReported` event fired. |
| Valid token, payload contains `messageid`, row **does not** match | `200` (so the provider stops retrying). `null` returned from the processor; a PSR-3 info log entry records the orphan. |
| Valid token, `messageid` missing or empty | `422` with `{status: 422, message: 'Missing messageid in payload'}` |
| Missing token | `401` with `{status: 401, message: 'Unauthorized'}` |
| Wrong token | `401` |
| Secret unconfigured | `500` with `{status: 500, message: 'Server misconfiguration'}` |

## Customising the URL prefix

By default the webhook lives at `/api/v1/sms/textsms/dlr`. To host it
elsewhere, set `TEXTSMS_WEBHOOK_PREFIX` in `.env`:

```env
TEXTSMS_WEBHOOK_PREFIX=webhooks/sms
```

Then the endpoint becomes `/webhooks/sms/textsms/dlr` and you configure
the TextSMS portal accordingly.

## Adding extra middleware

`config('textsms.webhook.middleware')` is an array of middleware aliases
that wraps the route group. The default is `['throttle:60,1']`. To add
e.g. Cloudflare IP filtering:

```php
// config/textsms.php
'webhook' => [
    'enabled'    => true,
    'url_prefix' => 'api/v1/sms',
    'middleware' => ['throttle:60,1', 'cloudflare.ip_filter'],
],
```

## Disabling the auto-registered route

If you'd rather register the route yourself (for a custom URL shape, a
different middleware stack, or to attach it to a non-default route file),
disable auto-loading and publish the routes file:

```env
TEXTSMS_WEBHOOK_ENABLED=false
```

```bash
php artisan vendor:publish --tag=textsms-routes
```

The published file lands at `routes/textsms-webhooks.php`. Include it
from wherever makes sense in your application.

## HMAC-signed webhooks (future)

TextSMS does not sign its payloads, so the token-bearer pattern is the
strongest available defence today. Future providers (Twilio, Africa's
Talking) do sign payloads with HMAC; the SDK roadmap includes shipping
an `HmacSignedMiddleware` alongside the current `VerifyTextSmsCallback`
when those providers are added.

## Verifying the webhook locally

Use `ngrok` or `localtunnel` to expose `php artisan serve` to TextSMS:

```bash
php artisan serve
ngrok http 8000
```

Set the portal callback URL to the ngrok-issued HTTPS URL plus
`/api/v1/sms/textsms/dlr?token=...` and send a test SMS via
`php artisan textsms:test`. Watch your log for the incoming DLR POST.
