<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('rejects missing outbound credentials before any row or request', function (string $field, string $operation): void {
    config()->set('textsms.providers.textsms.'.$field, '   ');
    Http::fake(['*' => Http::response(['credit' => 42])]);
    $sender = app(SmsSender::class);
    $invoke = match ($operation) {
        'send' => fn () => $sender->send('0700000000', new SmsMessage('Synthetic credential check')),
        'bulk' => fn () => $sender->sendBulk([['recipient' => '0700000000', 'message' => new SmsMessage('Synthetic credential check')]]),
        'balance' => fn () => $sender->balance(),
    };

    expect($invoke)->toThrow(SmsProviderException::class, 'credentials');
    expect(SmsDispatch::count())->toBe(0);
    Http::assertNothingSent();
})->with(['api_key', 'partner_id'])->with(['send', 'bulk', 'balance']);

it('keeps passive callbacks usable without outbound credentials', function (): void {
    config()->set('textsms.providers.textsms.api_key', '');
    config()->set('textsms.providers.textsms.partner_id', '');
    $row = SmsDispatch::create([
        'provider' => 'textsms', 'provider_message_id' => 'credential-passive-id',
        'recipient' => '254700000000', 'sender_id' => 'SYNTHETIC', 'body' => 'Synthetic callback', 'status' => 'sent',
    ]);

    $result = app(SmsDeliveryReportProcessor::class)->recordDeliveryReport(['messageid' => 'credential-passive-id', 'status' => 'Delivered']);
    expect($result?->getKey())->toBe($row->getKey())
        ->and($row->fresh()->dlr_payload)->toBe(['messageid' => 'credential-passive-id', 'status' => 'Delivered']);
    Http::assertNothingSent();
});

it('rejects HTTP balance failures even when the body looks successful', function (int $status): void {
    Http::fake(['*' => Http::response(['credit' => 42], $status)]);
    expect(fn () => app(SmsSender::class)->balance())->toThrow(SmsProviderException::class, 'balance');
    expect(SmsDispatch::count())->toBe(0);
})->with([302, 401, 422, 429, 500, 503]);

it('preserves a balance provider refusal instead of printing it as credit', function (): void {
    Http::fake(['*' => Http::response(['response-code' => 1006, 'response-description' => 'Synthetic invalid credentials'])]);
    $error = null;
    try {
        app(SmsSender::class)->balance();
    } catch (SmsProviderException $exception) {
        $error = $exception;
    }
    expect($error)->toBeInstanceOf(SmsProviderException::class)
        ->and($error?->providerCode)->toBe('1006')
        ->and($error?->providerDescription)->toBe('Synthetic invalid credentials');
});

it('rejects malformed balance payloads', function (mixed $payload): void {
    Http::fake(['*' => Http::response($payload)]);
    expect(fn () => app(SmsSender::class)->balance())->toThrow(SmsProviderException::class);
})->with([
    'HTML' => ['<html>Synthetic error</html>'],
    'empty object' => ['{}'],
    'list' => [[['credit' => 42]]],
    'malformed code' => [['response-code' => [], 'credit' => 42]],
    'error details' => [['errors' => ['apikey' => ['Synthetic invalid key']]]],
]);

it('returns valid numeric credit including zero without rewriting the provider payload', function (int|float|string $credit): void {
    $payload = ['credit' => $credit];
    Http::fake(['*' => Http::response($payload)]);
    expect(app(SmsSender::class)->balance())->toBe($payload);
    Http::assertSentCount(1);
})->with([0, 42, 12.5, '0.00', '42.50']);

it('keeps valid free-form balance data without inventing an unverified credit schema', function (): void {
    $payload = ['response-code' => 200, 'account' => ['synthetic' => true], 'balance' => '42.50'];
    Http::fake(['*' => Http::response($payload)]);
    expect(app(SmsSender::class)->balance())->toBe($payload);
});

it('returns a failing balance command exit for a real provider error payload', function (): void {
    Http::fake(['*' => Http::response(['response-code' => 1006, 'response-description' => 'Synthetic balance refusal'])]);
    $this->artisan('textsms:balance')->doesntExpectOutput('Provider balance:')->assertExitCode(1);
});
