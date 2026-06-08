# Writing a custom SMS provider

The bundled `TextSmsGateway` is the reference implementation. Adding a
new provider (Africa's Talking, Twilio, an in-house aggregator) means
implementing the same contracts and rebinding them in config.

## 1. Implement `SmsSender`

```php
<?php

namespace App\Sms\Gateways;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\LoggerInterface;

final class AfricasTalkingGateway implements SmsSender
{
    public const PROVIDER = 'africastalking';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $username,
        private readonly string $defaultSenderId,
        private readonly PhoneNumberNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        $normalised = $this->normalizer->normalize($recipient)
            ?? throw new SmsDispatchException("Invalid recipient: {$recipient}");

        // 1. Pre-insert a `pending` row so every send leaves an audit trail
        //    even if the HTTP call dies mid-flight.
        $dispatch = SmsDispatch::create([
            'provider' => self::PROVIDER,
            'recipient' => $normalised,
            'sender_id' => $message->senderId ?? $this->defaultSenderId,
            'body' => trim($message->body),
            'client_message_id' => $message->clientReference,
            'status' => 'pending',
            'attempt_count' => 1,
            'metadata' => $message->metadata,
            'notifiable_type' => $notifiable?->getMorphClass(),
            'notifiable_id' => $notifiable?->getKey(),
        ]);

        // 2. POST to the provider …
        // 3. On success: $dispatch->update(['status' => 'sent', …])
        //                SmsDispatchAccepted::dispatch($dispatch);
        // 4. On failure: $dispatch->update(['status' => 'failed', …])
        //                SmsDispatchFailed::dispatch($dispatch, providerCode: …);
        //                throw new SmsProviderException(…);

        return $dispatch->refresh();
    }

    public function sendBulk(array $items): Collection { /* ... */ }

    public function balance(): array { /* ... */ }
}
```

## 2. (Optional) implement `SmsDeliveryReportProcessor`

If the provider posts DLR callbacks and you want them reconciled into
`sms_dispatches`, implement the second contract on the same class:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;

final class AfricasTalkingGateway implements SmsSender, SmsDeliveryReportProcessor
{
    // ... existing send/sendBulk/balance ...

    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        $messageId = (string) ($payload['id'] ?? '');

        if ($messageId === '') {
            return null;
        }

        $dispatch = SmsDispatch::query()
            ->where('provider', self::PROVIDER)
            ->where('provider_message_id', $messageId)
            ->first();

        if ($dispatch === null) {
            return null;
        }

        $rawStatus = strtolower((string) ($payload['status'] ?? ''));
        $deliveryStatus = match (true) {
            str_contains($rawStatus, 'success') => 'delivered',
            str_contains($rawStatus, 'failed')  => 'failed',
            default                             => 'unknown',
        };

        $dispatch->update([
            'status' => $deliveryStatus === 'delivered' ? 'delivered' : $dispatch->status,
            'delivered_at' => $deliveryStatus === 'delivered' ? now() : $dispatch->delivered_at,
            'failed_at' => $deliveryStatus === 'failed' ? now() : $dispatch->failed_at,
            'dlr_payload' => $payload,
        ]);

        SmsDeliveryReported::dispatch($dispatch->refresh(), $deliveryStatus, $rawStatus, $payload);

        return $dispatch;
    }
}
```

## 3. Bind the gateway in config

```php
// config/textsms.php
return [
    // …
    'default_sender' => \App\Sms\Gateways\AfricasTalkingGateway::class,

    'providers' => [
        // … keep the textsms block for fallback / reference …

        'africastalking' => [
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'username' => env('AFRICASTALKING_USERNAME'),
            'default_sender_id' => env('AFRICASTALKING_SENDER_ID'),
        ],
    ],
];
```

Then register the constructor wiring in your `AppServiceProvider`:

```php
$this->app->singleton(\App\Sms\Gateways\AfricasTalkingGateway::class, fn ($app) =>
    new \App\Sms\Gateways\AfricasTalkingGateway(
        apiKey: config('textsms.providers.africastalking.api_key'),
        username: config('textsms.providers.africastalking.username'),
        defaultSenderId: config('textsms.providers.africastalking.default_sender_id'),
        normalizer: $app->make(PhoneNumberNormalizer::class),
        logger: $app->make(\Psr\Log\LoggerInterface::class),
    )
);
```

## 4. Multi-provider failover (extension recipe — not shipped)

If you need a fallback chain, wrap multiple senders with a thin
`FailoverSmsSender`:

```php
final class FailoverSmsSender implements SmsSender
{
    /** @param list<SmsSender> $senders Tried in order until one succeeds. */
    public function __construct(private readonly array $senders) {}

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        $last = null;
        foreach ($this->senders as $sender) {
            try {
                return $sender->send($recipient, $message, $notifiable);
            } catch (SmsProviderException $e) {
                $last = $e;
                continue;
            }
        }
        throw $last ?? new SmsProviderException('All providers exhausted.');
    }
    // sendBulk + balance similarly delegate.
}
```

Bind in `AppServiceProvider`:

```php
$this->app->bind(SmsSender::class, fn ($app) => new \App\Sms\FailoverSmsSender([
    $app->make(\MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway::class),
    $app->make(\App\Sms\Gateways\AfricasTalkingGateway::class),
]));
```

## 5. Test your implementation against the same regression set

Copy the gateway test file from this package and adapt the
`Http::fake([...])` URLs to match your provider. The 15 cases (happy
path, low-credit response, validation 422, unparseable 503, bulk
matching, bulk validation rejection, DLR happy / failed / unknown,
balance) are the **minimum** coverage for a production provider
adapter.
