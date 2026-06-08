# Queueing strategies

By default, `TextSmsGateway::send()` makes a **synchronous HTTP call** to
the provider. For low-volume usage this is fine; for high throughput, you
have two options.

## Pattern A: queue the notification (default Laravel behaviour)

The simplest path. Add `ShouldQueue` to your notification class:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;
    // ... via(), toSms(), toMail()
}
```

Laravel's notification dispatcher queues the notification; the queue
worker invokes the SMS channel; the channel makes the HTTP call inside
the worker. Set `QUEUE_CONNECTION=database` (or `redis`) in production.

## Pattern B: a queued-sender decorator (recipe — not shipped)

When you want the SMS dispatch itself wrapped in a job (regardless of
whether the notification is queued), decorate the `SmsSender`:

```php
<?php

namespace App\Sms;

use App\Jobs\DispatchSmsJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

final class QueuedSmsSender implements SmsSender
{
    public function __construct(private readonly SmsSender $inner) {}

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        // Pre-insert a `pending` row synchronously so the caller has
        // something to reference, then offload the HTTP call.
        $row = SmsDispatch::create([
            'provider' => 'queued',
            'recipient' => $recipient,
            'sender_id' => $message->senderId ?? config('textsms.providers.textsms.default_sender_id'),
            'body' => $message->body,
            'status' => 'pending',
            'metadata' => $message->metadata,
        ]);

        DispatchSmsJob::dispatch($row->ulid, $recipient, $message, $notifiable?->getMorphClass(), $notifiable?->getKey());

        return $row;
    }

    public function sendBulk(array $items): Collection
    {
        return collect($items)->map(fn (array $item): SmsDispatch =>
            $this->send($item['recipient'], $item['message'], $item['notifiable'] ?? null)
        );
    }

    public function balance(): array
    {
        return $this->inner->balance();
    }
}
```

The job:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

class DispatchSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly string $pendingDispatchUlid,
        public readonly string $recipient,
        public readonly SmsMessage $message,
        public readonly ?string $notifiableType,
        public readonly mixed $notifiableId,
    ) {}

    public function handle(): void
    {
        // Resolve the real sender (the inner gateway, not the queued decorator).
        $real = app(TextSmsGateway::class);

        $notifiable = $this->notifiableType !== null && $this->notifiableId !== null
            ? app($this->notifiableType)->newQuery()->find($this->notifiableId)
            : null;

        $real->send($this->recipient, $this->message, $notifiable);

        // Delete the placeholder row — the inner gateway created its own
        // authoritative row. (Or merge them — implementation choice.)
        SmsDispatch::query()->where('ulid', $this->pendingDispatchUlid)->delete();
    }
}
```

Bind in `AppServiceProvider`:

```php
$this->app->bind(SmsSender::class, function ($app) {
    return new \App\Sms\QueuedSmsSender(
        inner: $app->make(\MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway::class),
    );
});
```

## Recommendation

For most applications, **Pattern A is enough.** Queue the notification,
let it run inside the worker, done. Pattern B adds complexity (two rows
per dispatch, queue retry semantics, etc.) and is only worth it when you
need per-send job-level retry policies that differ from the notification's
own retry policy.

## What this package does NOT do

The package does not bundle either pattern. The `ShouldQueue` decision is
yours (it depends on whether your notification has other heavy channels);
the queued-sender decorator is application-specific (the job's retry
strategy depends on your queue infrastructure).
