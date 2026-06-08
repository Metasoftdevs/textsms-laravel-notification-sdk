# Events

Three Laravel events are dispatched by the gateway to surface every
delivery-lifecycle transition. **Consumers wire their own audit,
domain-reconciliation, and alerting logic by subscribing listeners — the
package itself never subscribes to its own events.**

## Catalogue

### `SmsDispatchAccepted`

Fired the moment the provider accepts a dispatch — i.e. the row has just
transitioned from `pending` to `sent` and carries a `provider_message_id`.

| Property | Type | Notes |
|---|---|---|
| `$dispatch` | `SmsDispatch` | The persisted row in its post-accept state. |

### `SmsDispatchFailed`

Fired when the provider rejects a dispatch (any non-200 code, validation
error, transport failure, or unparseable response).

| Property | Type | Notes |
|---|---|---|
| `$dispatch` | `SmsDispatch` | The persisted row in its `failed` state. |
| `$providerCode` | `?string` | Provider error code, e.g. `1004`. |
| `$providerDescription` | `?string` | Human-readable description from the provider. |
| `$providerErrors` | `?array` | Structured `errors` map from validation responses (HTTP 422 shape). |
| `$parseSource` | `?string` | `'responses'` \| `'top-level'` \| `'unparseable'`. |

### `SmsDeliveryReported`

Fired each time the DLR webhook posts a delivery report against a
previously dispatched message.

| Property | Type | Notes |
|---|---|---|
| `$dispatch` | `SmsDispatch` | The persisted row in its post-DLR state. |
| `$deliveryStatus` | `string` | `'delivered'` \| `'failed'` \| `'unknown'`. |
| `$rawStatus` | `string` | Lowercased status string straight from the provider payload. |
| `$payload` | `array` | Full decoded JSON the provider posted. |

## Wiring listeners

```php
// app/Providers/EventServiceProvider.php

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;

protected $listen = [
    SmsDispatchAccepted::class => [\App\Listeners\LogSmsAccepted::class],
    SmsDispatchFailed::class   => [\App\Listeners\AlertOpsOnSmsFailure::class],
    SmsDeliveryReported::class => [\App\Listeners\SyncReceiptFromSmsDelivery::class],
];
```

## Listener examples

### Example 1 — audit log on every accept / fail

```php
// app/Listeners/LogSmsAccepted.php
namespace App\Listeners;

use App\Models\AuditLog;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;

class LogSmsAccepted
{
    public function handle(SmsDispatchAccepted $event): void
    {
        AuditLog::create([
            'kind' => 'sms.sent',
            'subject_type' => $event->dispatch->notifiable_type,
            'subject_id' => $event->dispatch->notifiable_id,
            'metadata' => [
                'sms_dispatch_ulid' => $event->dispatch->ulid,
                'provider' => $event->dispatch->provider,
                'provider_message_id' => $event->dispatch->provider_message_id,
                'recipient' => $event->dispatch->recipient,
            ],
        ]);
    }
}
```

### Example 2 — page on-call for low credits

```php
// app/Listeners/AlertOpsOnSmsFailure.php
namespace App\Listeners;

use App\Notifications\PagerDutyAlert;
use Illuminate\Support\Facades\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;

class AlertOpsOnSmsFailure
{
    public function handle(SmsDispatchFailed $event): void
    {
        if ($event->providerCode === '1004') {
            Notification::route('pagerduty', config('alerts.oncall_key'))
                ->notify(new PagerDutyAlert(
                    summary: 'SMS provider credits exhausted',
                    severity: 'critical',
                    custom_details: [
                        'provider_code' => '1004',
                        'provider_description' => $event->providerDescription,
                    ],
                ));
        }
    }
}
```

### Example 3 — reconcile a domain row from a DLR

```php
// app/Listeners/SyncReceiptFromSmsDelivery.php
namespace App\Listeners;

use App\Models\Receipt;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;

class SyncReceiptFromSmsDelivery
{
    public function handle(SmsDeliveryReported $event): void
    {
        $receiptId = data_get($event->dispatch->metadata, 'receipt_id');

        if ($receiptId === null) {
            return; // not a receipt-driven SMS
        }

        $receipt = Receipt::find($receiptId);

        if ($receipt === null) {
            return;
        }

        if ($event->deliveryStatus === 'delivered') {
            $receipt->update([
                'sms_status' => 'sent',
                'sms_delivered_at' => $event->dispatch->delivered_at,
            ]);
        } elseif ($event->deliveryStatus === 'failed') {
            $receipt->update([
                'sms_status' => 'failed',
                'sms_delivered_at' => null,
                'resend_required' => true,
                'resend_reason' => 'SMS delivery failed: '.$event->rawStatus,
            ]);
        }
    }
}
```

## Queued listeners

Each event class uses `Illuminate\Foundation\Events\Dispatchable` so
listeners can implement `ShouldQueue` to defer work to the queue:

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;

class HeavyDeliveryReconciliation implements ShouldQueue
{
    public function handle(SmsDeliveryReported $event): void
    {
        // ... offloaded work
    }
}
```

## Asserting events in tests

```php
use Illuminate\Support\Facades\Event;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;

Event::fake([SmsDispatchAccepted::class]);

// trigger your code path …

Event::assertDispatched(
    SmsDispatchAccepted::class,
    fn (SmsDispatchAccepted $e): bool => $e->dispatch->recipient === '254712345678',
);
```
