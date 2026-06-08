# Bulk batching above 20

`TextSmsGateway::sendBulk()` accepts at most **20 messages per call** (the
provider's hard limit). Beyond that, the gateway throws an
`SmsDispatchException` rather than silently splitting — the caller owns the
batching strategy.

## Recipe: chunk-and-send

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

$sender = app(SmsSender::class);

$recipients = User::query()->where('campaign_id', $campaignId)->get();

collect($recipients)
    ->chunk(20)
    ->each(function ($chunk) use ($sender, $campaignId): void {
        $items = $chunk->map(fn ($user): array => [
            'recipient' => $user->phone_number,
            'message' => new SmsMessage(
                body: __('campaign.body', ['name' => $user->name]),
                clientReference: 'campaign-'.$campaignId.'-user-'.$user->id,
                metadata: ['campaign_id' => $campaignId, 'user_id' => $user->id],
            ),
            'notifiable' => $user,
        ])->all();

        $sender->sendBulk($items);
    });
```

## Recipe: per-batch retry on bulk-wide failure

If the provider rejects an entire batch (e.g. HTTP 422 with a top-level
validation error), the gateway raises a single `SmsProviderException`
**after** marking every row in the batch as failed. Catch and retry the
batch with a fresh `client_message_id` per item:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

try {
    $sender->sendBulk($items);
} catch (SmsProviderException $e) {
    Log::error('Bulk SMS rejected', [
        'provider_code' => $e->providerCode,
        'provider_description' => $e->providerDescription,
        'provider_errors' => $e->providerErrors,
        'chunk_size' => count($items),
    ]);

    // Inspect $e->providerErrors and re-batch as appropriate. For
    // 1003 (validation), fix the sender_id and retry. For 1004 (low
    // credits), pause the campaign and alert ops.
}
```

## Recipe: progress tracking

Persist a campaign-level row, then derive progress from `sms_dispatches`:

```sql
SELECT
    status,
    COUNT(*) AS count
FROM sms_dispatches
WHERE JSON_EXTRACT(metadata, '$.campaign_id') = ?
GROUP BY status;
```

(Use the appropriate JSON syntax for your driver — `metadata->>'$.campaign_id'`
in PostgreSQL, `JSON_VALUE(metadata, '$.campaign_id')` in MS SQL, etc.)

## Why the package doesn't ship a `SmsBatcher`

A batcher needs to know:
- Your queue strategy (sync? queued? rate-limited?)
- Your retry policy (immediate? exponential backoff? Move-to-dead-letter?)
- Your failure semantics (skip and continue? halt the campaign?)

Those are all application-specific decisions. The 20-item primitive plus
this recipe is intentionally lower-level so you can compose what your use
case needs.
