# Events

The default gateway emits two outbound Laravel events after recording
submission outcomes. Delivery callbacks are informational: they emit no
SDK or Eloquent events and invoke no application actions. See
[webhooks](webhooks.md).

## Catalogue

| Event | When | Properties |
|---|---|---|
| `SmsDispatchAccepted` | An accepted outcome was saved as `sent`. | `$dispatch`: `SmsDispatch` |
| `SmsDispatchFailed` | An unsuccessful outcome was saved as `failed`. | `$dispatch`, nullable `$providerCode`, `$providerDescription`, `$providerErrors`, `$parseSource` |

A failed event includes confirmed refusal and unknown acceptance. It does
not prove that nothing was sent. Read
`metadata.provider_diagnostics.provider_acceptance` before considering a new
send; an observed provider code alone is insufficient.

Normal sources are `responses` and `top-level`. Unknown-response sources
include `unparseable`, `unmatched`, `malformed-response`,
`duplicate-client-id`, `duplicate-provider-id`, `invalid-message-id` and
`http-error`. Transport failures have a null parse source. Unexpected extra
references generate a bounded warning without invalidating unrelated rows.

`SmsDeliveryReported` remains loadable for source and queued-event
compatibility, but the default gateway no longer emits it. Existing delivery
listeners receive no new callback events. Previously queued events can still
execute under the host's queue policy; deployment does not cancel jobs.
Callbacks neither classify delivery outcomes nor update application state.

## Recording and listener failures

For `send()` and `sendBulk()`, outcomes are recorded before events.
Recording is attempted for every bulk row before the first event; events
run in input order, only for successfully saved rows.

Immediate listener exceptions are reported to the application's exception
handler and contained. They cannot rewrite a sent row, replace a provider
exception or stop events for subsequent bulk rows. Reporter failures are
also contained. If both reporting and logging fail, no trace may remain.

Laravel stops an event's remaining synchronous listeners when one throws.
A later queued listener may therefore never be enqueued. Publication is
best effort, without a durable outbox. Essential listeners should contain
their own failures or be registered before listeners that can fail.

After-commit listeners, queue submission deferred until commit, and queue
workers run outside immediate dispatch isolation. Their failures follow
host/queue handling. Do not resend an SMS because deferred application work
failed: the send and database commit may already have happened.

If an outbound outcome cannot be saved, no event fires for that row and
`SmsProviderException` preserves the storage cause and recovery guidance:

- Accepted: the SMS was sent; do not resend.
- Unknown: the SMS may have been sent; do not resend automatically. This
  includes malformed/codeless/unmatched output and codes such as `1005`,
  `1007` and `4090` that do not establish send refusal.
- Rejected: a usable refusal with one of `1001`, `1002`, `1003`, `1004`,
  `1006`, `1009`, `1010`, `4091`, `4092` establishes rejection.

The gateway attempts an error log containing recovery identifiers and
`provider_acceptance`. Logging is best effort: its failure cannot replace
the recording exception. These guarantees apply to `send()`/`sendBulk()`.

## Registering listeners

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;

// app/Providers/EventServiceProvider.php
protected $listen = [
    SmsDispatchAccepted::class => [\App\Listeners\LogSmsAccepted::class],
    SmsDispatchFailed::class => [\App\Listeners\AlertOpsOnSmsFailure::class],
];
```

Listeners can implement Laravel's `ShouldQueue` and set
`public bool $afterCommit = true;` when the host wraps SDK calls in a
transaction. This prevents workers reading uncommitted rows but does not
make an external SMS transactional. Prefer starting outbound work after
the business transaction commits. `sent` means provider acceptance, not
confirmed handset delivery.

## Queued event restoration

Event classes use `SerializesModels`. A queued event stores the model
identifier and re-fetches the current dispatch when restored. Scalar fields
retain their original values. The restored row can differ from the original.

Producer and worker must resolve the same database, schema and table:
`textsms.database.connection`, `textsms.database.table`, `database.default`
and the named connection's target configuration all matter. Changing them
with jobs in flight can restore the same numeric ID from a different
database/table. Drain or deliberately migrate work before changing them.
Tenant connection restoration is application-owned.

Retain dispatch rows through the queue delay/retry window. Missing rows fail
restoration with `ModelNotFoundException`. A listener's
`$deleteWhenMissingModels` does not suppress this: Laravel restores a
`CallQueuedListener` job and applies that job class's policy.

## Asserting events

```php
use Illuminate\Support\Facades\Event;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;

Event::fake([SmsDispatchAccepted::class]);
// Trigger an outbound send in your test.
Event::assertDispatched(SmsDispatchAccepted::class);
```
