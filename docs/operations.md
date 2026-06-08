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

### Stuck rows (sent ≥ 24h, no DLR)

```sql
SELECT id, ulid, recipient, provider_message_id, sent_at
FROM sms_dispatches
WHERE status = 'sent'
  AND delivered_at IS NULL
  AND sent_at < NOW() - INTERVAL '24 hours'
ORDER BY sent_at;
```

If this query returns rows, either:
- The DLR webhook URL is misconfigured on the provider portal.
- The provider's DLR queue is backed up.
- The webhook secret rotated and the portal has the stale value.

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
| `1003` Validation Errors | Fix the sender ID / payload, then retry. |
| `1004` Low bulk credits | Top up credits, then retry. |
| `1006` Invalid credentials | Rotate API key. **Do not retry.** |
| HTTP transport / 5xx | Safe to retry immediately. |
| `unparseable` | Inspect `metadata.provider_diagnostics.raw_body_truncated`, fix root cause. |

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
4. Update the TextSMS portal "Callback URL" to use the new `?token=...`.
5. Watch the log for `401` responses for a few minutes — they indicate
   the portal hasn't picked up the new value yet.

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
   - `pending` → never made it past `preInsert`. Check application logs.
   - `failed`  → provider rejected. `response_code` and
     `metadata.provider_diagnostics` say why.
   - `sent`    → provider accepted but no DLR yet. Could be the carrier
     queue or a misconfigured webhook.
   - `delivered` → handset confirmed receipt. The customer either missed
     it or the number is wrong.

## Monitoring listeners

Wire ops alerts to the three events:

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
