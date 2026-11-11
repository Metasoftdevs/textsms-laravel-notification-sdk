<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('prints structured balance values and returns success', function (): void {
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('balance')->once()->andReturn(['credit' => 42, 'details' => ['synthetic' => true], 'missing' => null]);
    app()->instance(SmsSender::class, $sender);

    $this->artisan('textsms:balance')->expectsOutput('Provider balance:')
        ->expectsTable(['Field', 'Value'], [['credit', '42'], ['details', '{"synthetic":true}'], ['missing', '']])
        ->assertSuccessful();
    Http::assertNothingSent();
});

it('returns failure when the balance operation raises a package exception', function (): void {
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('balance')->once()->andThrow(new SmsProviderException('Synthetic lookup failure'));
    app()->instance(SmsSender::class, $sender);

    $this->artisan('textsms:balance')->expectsOutput('Balance lookup failed: Synthetic lookup failure')->assertExitCode(1);
    Http::assertNothingSent();
});

it('passes explicit test-command body and sender options to the sender', function (): void {
    $row = SmsDispatch::create([
        'provider' => 'synthetic', 'recipient' => '254700000000', 'sender_id' => 'SYNTHETIC',
        'body' => 'Explicit synthetic body', 'status' => 'sent',
    ]);
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('send')->once()->withArgs(fn (string $recipient, SmsMessage $message): bool => $recipient === '0700000000'
        && $message->body === 'Explicit synthetic body' && $message->senderId === 'SYNTHETIC')->andReturn($row);
    app()->instance(SmsSender::class, $sender);

    $this->artisan('textsms:test', ['phone' => '0700000000', '--body' => 'Explicit synthetic body', '--sender' => 'SYNTHETIC'])
        ->expectsOutput('SMS accepted by provider.')->assertSuccessful();
    Http::assertNothingSent();
});

it('returns invalid input for a test-command dispatch validation failure', function (): void {
    $sender = Mockery::mock(SmsSender::class);
    $sender->shouldReceive('send')->once()->andThrow(new SmsDispatchException('Synthetic invalid input'));
    app()->instance(SmsSender::class, $sender);

    $this->artisan('textsms:test', ['phone' => 'invalid', '--body' => 'Synthetic command'])
        ->expectsOutput('Bad input: Synthetic invalid input')->assertExitCode(2);
    Http::assertNothingSent();
});

it('shows the selected dispatch body metadata and delivery payload by ULID', function (): void {
    $row = SmsDispatch::create([
        'provider' => 'synthetic', 'recipient' => '254700000000', 'sender_id' => 'SYNTHETIC',
        'body' => 'Synthetic inspection body', 'status' => 'sent',
        'metadata' => ['case' => 'coverage'], 'dlr_payload' => ['status' => 'buffered'],
    ]);

    $this->artisan('textsms:dispatch:show', ['ulid' => $row->ulid])
        ->expectsOutput("Dispatch #{$row->id} ({$row->ulid})")
        ->expectsOutput('Body:')->expectsOutput('  Synthetic inspection body')
        ->expectsOutput('Metadata:')->expectsOutputToContain('"case": "coverage"')
        ->expectsOutput('DLR payload:')->expectsOutputToContain('"status": "buffered"')->assertSuccessful();
    Http::assertNothingSent();
});

it('returns failure when the requested dispatch ULID is absent', function (): void {
    $ulid = '00000000000000000000000000';
    $this->artisan('textsms:dispatch:show', ['ulid' => $ulid])
        ->expectsOutput("No sms_dispatches row matched ulid={$ulid}.")->assertExitCode(1);
    Http::assertNothingSent();
});
