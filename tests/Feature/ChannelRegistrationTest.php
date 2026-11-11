<?php

declare(strict_types=1);

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;

it('does not resolve the notification manager while booting the package', function (): void {
    expect(app()->resolved(ChannelManager::class))->toBeFalse();
});

it('dispatches through the current sender binding for a new channel on either manager path', function (bool $alreadyResolved): void {
    Http::preventStrayRequests();
    if ($alreadyResolved) {
        $manager = app(ChannelManager::class);
        $manager->extend('sms', fn () => throw new RuntimeException('Synthetic old driver factory'));
        (new TextSmsNotificationServiceProvider(app()))->boot();
    }
    $notifiable = new class
    {
        use Notifiable;

        public function routeNotificationForSms(): string
        {
            return '0700000000';
        }
    };
    $message = new SmsMessage('Synthetic registered channel');
    $notification = new class($message) extends Notification
    {
        public function __construct(private readonly SmsMessage $message) {}

        public function via(object $notifiable): array
        {
            return ['sms'];
        }

        public function toSms(object $notifiable): SmsMessage
        {
            return $this->message;
        }
    };
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('send')->once()
        ->withArgs(fn (string $recipient, SmsMessage $sent, mixed $owner): bool => $recipient === '0700000000' && $sent === $message && $owner === null)
        ->andReturn(new SmsDispatch(['status' => 'sent']));
    app()->instance(SmsSender::class, $sender);

    app(ChannelManager::class)->sendNow($notifiable, $notification);

    Http::assertNothingSent();
})->with(['later resolution' => [false], 'already resolved' => [true]]);
