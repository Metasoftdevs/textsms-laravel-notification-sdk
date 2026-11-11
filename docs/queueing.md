# Queueing notifications

`TextSmsGateway::send()` makes a synchronous HTTP request. To move that
work into a worker, use Laravel's standard queued notification support:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

class OrderShipped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $trackingNumber) {}

    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: __('sms.order_shipped', ['tracking' => $this->trackingNumber]),
            clientReference: 'order-'.$this->trackingNumber,
        );
    }
}

$user->notify((new OrderShipped('TRK-12345'))->afterCommit());
```

Configure a host queue connection such as `database` or `redis` and run a
worker using `php artisan queue:work`. The `sync` connection still executes
the notification in the current process. Laravel schedules the notification
and invokes the SMS channel in the worker; the channel then calls the sender.
The package adds no queued-sender decorator, placeholder dispatch row or
automatic resend job. See the [receipt example](../examples/ReceiptNotification.php).

## Transactions and retries

`afterCommit()` defers queue submission until the business transaction
commits. A direct synchronous SMS send cannot roll back with database work.
Making a listener run after commit solves a different boundary; it does not
make the original send transactional.

Choose host worker timeouts, queue visibility/retry settings and deployment
handling for the provider's HTTP budget. A worker crash, job redelivery or
retry can invoke a notification again after an earlier send was accepted
or became uncertain. Client references and queue uniqueness do not establish
provider idempotency. Review the dispatch diagnostics and provider evidence
before replaying work; do not add unconditional resend retries.

The sender's own policy allows at most two Laravel HTTP attempts, with the
second permitted only for structured cURL 6/7 DNS/connect failures. It does
not retry HTTP responses or ambiguous failures, and send redirects are
disabled. This policy does not guarantee exactly-once sending or cap every
lower-level transport action. See [operations](operations.md).

The SMS channel catches package dispatch/provider exceptions, logs them and
returns null. Therefore a completed notification job does not prove that
the provider accepted the SMS. Inspect the saved outcome and recovery logs;
`failed` alone does not prove that nothing was sent.

## Outbound listeners and passive callbacks

Outbound accepted/failed listeners may implement `ShouldQueue`; use
after-commit listener handling when their rows are part of a transaction.
Immediate listener isolation is best effort, and queued work follows the
host's retry/error policy. See [events](events.md) for serialization,
retention and publication limits.

Delivery callbacks only replace the latest sanitized `dlr_payload` and
preserve state and timestamps. They emit no SDK or Eloquent events and
invoke no application actions. Queueing a notification does not enable
delivery reconciliation, polling or a delivery listener.
