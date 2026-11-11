# Bulk batching above 20

`TextSmsGateway::sendBulk()` accepts at most **20 messages per call** (the
provider's hard limit). Beyond that, the gateway throws an
`SmsDispatchException` rather than silently splitting — the caller owns the
batching strategy.

## Preparation and response matching

An empty batch is a no-op. For a nonempty batch, every item is validated
before any rows are inserted: recipient, message type and nonempty body,
optional model owner, unique nonblank client reference (up to 255
characters), and UTF-8 outbound fields. Invalid input raises an indexed
`SmsDispatchException` with no rows, events, or provider requests.

Pending rows are then inserted in one transaction on the dispatch model's
configured connection. Failures before commit roll back the batch and no
provider request is made. A host callback that throws after commit can
leave the complete pending batch behind; the preparation exception still
states that no request was sent. The provider POST runs after the insertion
transaction scope. A consumer's enclosing transaction can still roll back
after TextSMS accepted the SMS: send after the business transaction commits.

Results remain in input order and are matched by exact `clientsmsid`,
never by response position. Missing, duplicate or malformed references,
colliding provider IDs, contradictory envelopes, and code 200 without a
usable provider ID produce uncertain outcomes. Independently valid sibling
responses are retained. A success-shaped response on an HTTP error is also
uncertain. A bounded warning contains anomaly counts only.

An uncertain outcome is stored as `failed` with
`metadata.provider_diagnostics.provider_acceptance = unknown`; this does
not establish that the SMS was refused. See [events](events.md) for
diagnostic sources and recording failures.

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

## Handling bulk-wide failure

For a whole-response failure, the gateway attempts to record every row
before raising one `SmsProviderException`. Per-item failures instead return
the resulting rows. A storage failure can also raise this exception after
accepted or uncertain sends; its message states what is known. Inspect the
exception and rows before considering recovery. Do not retry a whole batch
merely because this exception was thrown, or because rows are `failed`.

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

try {
    $sender->sendBulk($items);
} catch (SmsProviderException $e) {
    Log::error('Bulk SMS requires investigation', [
        'provider_code' => $e->providerCode,
        'chunk_size' => count($items),
    ]);

    // Reconcile accepted and unknown attempts before any resend.
    // A confirmed refusal can be retried after its cause is fixed;
    // 1003 means invalid mobile number, 1004 means insufficient credits.
}
```

Changing the client reference does not make a resend safe. Provider
idempotency and duplicate suppression remain unverified.

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

(Use the appropriate JSON syntax for your driver — `metadata->>'campaign_id'`
in PostgreSQL, `JSON_VALUE(metadata, '$.campaign_id')` in MS SQL, etc.)

## Why the package doesn't ship a `SmsBatcher`

A batcher needs to know:
- Your queue strategy (sync? queued? rate-limited?)
- Your retry policy (immediate? exponential backoff? Move-to-dead-letter?)
- Your failure semantics (skip and continue? halt the campaign?)

Those are all application-specific decisions. The 20-item primitive plus
this recipe is intentionally lower-level so you can compose what your use
case needs.
