# Operations

Day-to-day runbook for operating the SMS pipeline.

## Health checks

### Provider balance

```bash
php artisan textsms:balance
```

Cron this weekly and alert when balance drops below a threshold:

```cron
0 9 * * 1 /usr/bin/php /var/www/yourapp/artisan textsms:balance >/var/log/sms-balance.log 2>&1
```

### Recent dispatches

```bash
php artisan tinker --execute="echo \App\MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch::query()->where('created_at', '>', now()->subHour())->groupBy('status')->selectRaw('status, count(*) as count')->pluck('count', 'status');"
```

### Accepted submissions without callback information after 24 hours

```sql
SELECT id, ulid, recipient, provider_message_id, sent_at
FROM sms_dispatches
WHERE status = 'sent'
  AND dlr_payload IS NULL
  AND sent_at < NOW() - INTERVAL '24 hours'
ORDER BY sent_at;
```

If this query returns rows, investigate:

- Whether push reports are supported and enabled for the account, and
  whether their method/schema matches the SDK endpoint. The live provider
  contract is unconfirmed in this checkout; see [webhooks](webhooks.md).
- The registered callback URL and account configuration.
- The provider's DLR queue is backed up.
- The webhook secret rotated and the portal has the stale value.
- A report arrived before the send result's provider ID was saved and
  could not be matched. No polling/reconciliation command is shipped yet.

These are diagnostic possibilities, not evidence that a particular cause
occurred or that an SMS was not sent. Preserve uncertainty before resending.

## Inspecting a single dispatch

```bash
php artisan textsms:dispatch:show 01J8YK2C5X8WZJY3D5KQXT8MR2
```

The output includes the full `metadata` and `dlr_payload` blocks, which
is where provider diagnostics and DLR audit data live.

## Retrying failed dispatches (recipe — not shipped as an artisan command)

The retry semantics depend on **why** a dispatch failed:

| `response_code` | Recommended action |
|---|---|
| Confirmed refusal `1003` | Correct the mobile number before considering a new attempt. |
| `1004` Low bulk credits | Top up credits, then retry. |
| `1006` Invalid credentials | Rotate API key. **Do not retry.** |
| HTTP transport / 5xx | Outcome may be unknown. Reconcile before resending. |
| Unparseable or uncertain correlation | Inspect `metadata.provider_diagnostics`; do not resend automatically. |

Check `metadata.provider_diagnostics.provider_acceptance` before retrying.
`unknown` means TextSMS may have accepted the SMS. A `failed` row or a
provider-looking code alone does not prove refusal: contradictory envelopes
and incomplete correlation retain unknown acceptance. Recording exceptions
can also follow accepted sends; follow their recovery message. Existing
send policy permits one additional Laravel HTTP attempt, after 200ms,
only when structured cURL context proves DNS resolution/connect failure
(error 6/7). A timeout, empty reply, TLS failure, context-free exception or
received HTTP response is not automatically retried. Send redirects are
disabled. Balance retry behavior is unchanged.

This bounds package/Laravel retries, not every possible lower-level
transport action: installed Guzzle has its own internal recovery behavior.
It does not establish exactly-once delivery or provider idempotency.

A simple retry recipe:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

$sender = app(SmsSender::class);

SmsDispatch::query()
    ->where('status', 'failed')
    ->whereIn('response_code', ['1004']) // only credit-related failures
    ->where('created_at', '>', now()->subHours(2))
    ->chunkById(50, function ($rows) use ($sender): void {
        foreach ($rows as $row) {
            if (data_get($row->metadata, 'provider_diagnostics.provider_acceptance') !== 'rejected') {
                continue; // uncertainty is not permission to resend
            }
            $sender->send(
                recipient: $row->recipient,
                message: new SmsMessage(
                    body: $row->body,
                    senderId: $row->sender_id,
                    metadata: $row->metadata ?? [],
                ),
            );
        }
    });
```

Wrap this in a custom artisan command to suit your retry policy.

## Rotating the DLR secret

1. Generate a new secret: `php -r "echo bin2hex(random_bytes(32));"`
2. Update `.env` with the new `TEXTSMS_DLR_SECRET`.
3. `php artisan config:clear`.
4. Update the account-confirmed callback registration to send the new
   token through its supported query-token or header mechanism.
5. Investigate `401` responses as authentication failures. A stale
   registration is one possible cause; a 401 does not prove propagation lag.

## Rotating the API key

1. Generate a new key on the TextSMS portal. **Copy it immediately** —
   the portal shows it only once.
2. Update `.env` with the new `TEXTSMS_API_KEY`.
3. `php artisan config:clear`.
4. Restart queue workers (`php artisan queue:restart`) so they pick up
   the new value from the cached config.
5. Send a test SMS via `php artisan textsms:test` to confirm acceptance.

## Forensics: "this customer says they didn't receive an SMS"

1. Find the dispatch row by recipient + approximate timestamp:
   ```sql
   SELECT id, ulid, status, sent_at, delivered_at, failed_at, response_code
   FROM sms_dispatches
   WHERE recipient = ?
     AND created_at BETWEEN ? AND ?
   ORDER BY created_at DESC;
   ```
2. Inspect it: `php artisan textsms:dispatch:show {ulid}`.
3. Branch on `status`:
   - `pending` → preparation completed, but the final outcome was not
     recorded. A crash or recording failure may have followed a real send;
     inspect recovery logs and reconcile before resending.
   - `failed` → local unsuccessful attempt, provider refusal, or
     unknown acceptance. Check `response_code`, `dlr_payload`, and
     `metadata.provider_diagnostics`; unknown acceptance can still mean sent.
   - `sent` → provider accepted the submission. Inspect `dlr_payload` for
     informational callback data; it does not change this status or prove
     delivery. A missing callback does not prove non-delivery.
   - Legacy `delivered`/`rejected` rows remain readable. New callbacks do not
     produce these states or update delivery/failure timestamps.

## Monitoring listeners

Wire ops alerts to outbound submission events:

```php
// EventServiceProvider
protected $listen = [
    SmsDispatchFailed::class => [
        \App\Listeners\AlertOpsOnLowCredits::class,
        \App\Listeners\IncidentTicketOnInvalidCredentials::class,
    ],
];
```

The package never alerts on its own. Hook it into your incident system
through these listeners.
