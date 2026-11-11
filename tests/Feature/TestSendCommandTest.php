<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

it('sends an actual UTC default test marker in a non-UTC application', function (): void {
    Http::preventStrayRequests();
    $previousTimezone = date_default_timezone_get();
    config()->set('app.timezone', 'Africa/Nairobi');
    date_default_timezone_set('Africa/Nairobi');
    $this->travelTo(Carbon::parse('2026-11-02 12:30:00', 'Africa/Nairobi'));
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('send')->once()
        ->withArgs(fn (string $recipient, SmsMessage $message): bool => $recipient === '0700000000'
            && $message->body === 'TextSMS SDK test at 2026-11-02 09:30:00 UTC')
        ->andReturn(new SmsDispatch(['status' => 'sent', 'provider' => 'synthetic']));
    app()->instance(SmsSender::class, $sender);

    try {
        $this->artisan('textsms:test', ['phone' => '0700000000'])->assertSuccessful()->run();
        Http::assertNothingSent();
    } finally {
        $this->travelBack();
        date_default_timezone_set($previousTimezone);
    }
});

it('does not label an unknown dispatch exception a provider rejection', function (): void {
    Http::preventStrayRequests();
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('send')->once()->andThrow(new SmsProviderException('Outcome unknown; resending is unsafe.'));
    app()->instance(SmsSender::class, $sender);

    $this->artisan('textsms:test', ['phone' => '0700000000', '--body' => 'Synthetic command'])
        ->expectsOutput('SMS dispatch failed: Outcome unknown; resending is unsafe.')
        ->assertExitCode(1);
    Http::assertNothingSent();
});
