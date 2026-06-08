<?php

declare(strict_types=1);

use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Notifications\Channels\SmsChannel;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway;
use Psr\Log\LoggerInterface;

/**
 * Coverage for the `'sms'` notification channel's branch points:
 *
 *  - happy path: dispatches via the gateway with the normalised recipient
 *  - silent skip when the notifiable has no routable phone
 *  - silent skip when the phone fails the normaliser's strict validator
 *  - swallow gateway exceptions so a co-dispatched mail channel survives
 */

/**
 * Minimal notification stub used across the four assertions below. Returns
 * an SmsMessage and exposes a `routeNotificationForSms()` override on the
 * anonymous notifiable.
 */
class ChannelTestNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(body: 'Hello receipt #42');
    }
}

/**
 * Anonymous notifiable that routes SMS to the phone supplied at construction.
 */
class ChannelTestNotifiable
{
    use Notifiable;

    public function __construct(public readonly ?string $phone) {}

    public function routeNotificationForSms(?Notification $notification = null): ?string
    {
        return (new KenyanE164Normalizer)->normalize($this->phone);
    }
}

beforeEach(function (): void {
    $this->fake = FakeSmsGateway::install();
    $this->channel = new SmsChannel(
        sender: $this->app->make(SmsSender::class),
        logger: $this->app->make(LoggerInterface::class),
    );
});

it('dispatches via the gateway with the normalised recipient and notification body', function (): void {
    $notifiable = new ChannelTestNotifiable('0712345678');

    $dispatch = $this->channel->send($notifiable, new ChannelTestNotification);

    expect($dispatch)->toBeInstanceOf(SmsDispatch::class);
    expect($this->fake->sent)->toHaveCount(1);
    expect($this->fake->sent[0]['recipient'])->toBe('254712345678');
    expect($this->fake->sent[0]['message']->body)->toBe('Hello receipt #42');
});

it('skips silently when the user has no phone on record', function (): void {
    $notifiable = new ChannelTestNotifiable(null);

    $dispatch = $this->channel->send($notifiable, new ChannelTestNotification);

    expect($dispatch)->toBeNull();
    $this->fake->assertNothingSent();
});

it('skips silently when phone cannot be normalised to Kenyan E.164', function (): void {
    $notifiable = new ChannelTestNotifiable('totally-not-a-phone');

    $dispatch = $this->channel->send($notifiable, new ChannelTestNotification);

    expect($dispatch)->toBeNull();
    $this->fake->assertNothingSent();
});

it('does NOT rethrow gateway exceptions, so the broader notification stays alive', function (): void {
    $this->fake->shouldThrow(new SmsProviderException('Simulated provider rejection.'));

    $notifiable = new ChannelTestNotifiable('0712345678');

    // Must not throw — failure stays inside the channel/gateway.
    $dispatch = $this->channel->send($notifiable, new ChannelTestNotification);

    expect($dispatch)->toBeNull();
});
