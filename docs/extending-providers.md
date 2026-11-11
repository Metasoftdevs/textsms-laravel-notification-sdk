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

If the provider posts callbacks, implement the second contract as a passive
payload recorder on the same class. Do not change submission state or invoke
model observers/events. The following skeleton assumes sanitized, validated
input; adapt field names at your provider's HTTP boundary:

```php
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;

final class AfricasTalkingGateway implements SmsSender, SmsDeliveryReportProcessor
{
    // ... existing send/sendBulk/balance ...

    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        $messageId = (string) ($payload['id'] ?? '');

        if ($messageId === '') {
            return null;
        }

        return SmsDispatch::withoutEvents(fn () => SmsDispatch::withoutTimestamps(function () use ($messageId, $payload) {
            $dispatch = SmsDispatch::query()
                ->where('provider', self::PROVIDER)
                ->where('provider_message_id', $messageId)
                ->first();

            if ($dispatch === null) {
                return null;
            }

            if (! $dispatch->update(['dlr_payload' => $payload])) {
                throw new \RuntimeException('Delivery report could not be recorded.');
            }

            return $dispatch;
        }));
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

## 4. Multi-provider failover (not shipped)

A provider exception alone is not permission to send through another
provider. It can follow an accepted send whose local recording failed, or
an unknown transport/response outcome. A blind catch-and-continue chain can
send duplicate messages and is intentionally not provided here.

If the application needs fallback, establish a provider-specific proof that
the previous attempt was not accepted, retain the diagnostic/recording
context, and authorize the new attempt under application policy. Do not
automatically fail over unknown or accepted outcomes. No general failover
or retry-policy abstraction is needed for the default gateway.

## 5. Test your implementation against the same regression set

Adapt this package's gateway, correlation, preparation, retry and passive
callback regressions to the provider's actual contract. Cover happy paths,
refusal and unknown outcomes, invalid input, bulk matching/ambiguity,
recording failures, listener isolation, bounded retries and balance. Use
sanitized wire fixtures before making live-provider guarantees.
