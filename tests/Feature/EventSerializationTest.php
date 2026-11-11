<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/** @param array<string, mixed> $attributes */
function eventSerializationDispatch(array $attributes = []): SmsDispatch
{
    return SmsDispatch::create($attributes + [
        'provider' => 'textsms', 'recipient' => '254700000000',
        'sender_id' => 'SERIALIZATION', 'body' => 'Synthetic event', 'status' => 'sent',
    ]);
}

it('rehydrates the current dispatch while retaining historical event data', function (string $kind): void {
    $row = eventSerializationDispatch(['status' => 'failed', 'metadata' => ['attempt' => 'first']]);
    $event = match ($kind) {
        'accepted' => new SmsDispatchAccepted($row),
        'failed' => new SmsDispatchFailed($row, '1004', 'Low credits', ['credit' => ['synthetic']], 'responses'),
        'dlr' => new SmsDeliveryReported($row, 'failed', 'undeliverable', ['messageid' => 'synthetic-id', 'status' => 'undeliverable']),
    };
    $serialized = serialize($event);
    $row->update(['status' => 'delivered', 'metadata' => ['attempt' => 'current']]);

    $restored = unserialize($serialized);
    expect($restored->dispatch)->not->toBe($row)
        ->and($restored->dispatch->getKey())->toBe($row->getKey())
        ->and($restored->dispatch->status)->toBe('delivered')
        ->and($restored->dispatch->metadata)->toBe(['attempt' => 'current']);
    if ($kind === 'failed') {
        expect($restored->providerCode)->toBe('1004')
            ->and($restored->providerDescription)->toBe('Low credits')
            ->and($restored->providerErrors)->toBe(['credit' => ['synthetic']])
            ->and($restored->parseSource)->toBe('responses');
    }
    if ($kind === 'dlr') {
        expect($restored->deliveryStatus)->toBe('failed')
            ->and($restored->rawStatus)->toBe('undeliverable')
            ->and($restored->payload)->toBe(['messageid' => 'synthetic-id', 'status' => 'undeliverable']);
    }
})->with(['accepted', 'failed', 'dlr']);

it('fails restoration when the dispatch was deleted', function (string $kind): void {
    $row = eventSerializationDispatch();
    $serialized = serialize(match ($kind) {
        'accepted' => new SmsDispatchAccepted($row),
        'failed' => new SmsDispatchFailed($row),
        'dlr' => new SmsDeliveryReported($row, 'unknown', 'buffered', []),
    });
    $row->delete();

    expect(fn () => unserialize($serialized))->toThrow(ModelNotFoundException::class);
})->with(['accepted', 'failed', 'dlr']);

it('preserves the send when a throwing first listener prevents a later listener from running', function (): void {
    Http::preventStrayRequests();
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [
        ['respose-code' => 200, 'messageid' => 'ordered-listener-id'],
    ]])]);
    $laterCalls = 0;
    Event::listen(SmsDispatchAccepted::class, function (): void {
        throw new RuntimeException('Synthetic first listener failure');
    });
    Event::listen(SmsDispatchAccepted::class, function () use (&$laterCalls): void {
        $laterCalls++;
    });

    $row = app(SmsSender::class)->send('0700000000', new SmsMessage('Synthetic ordered listeners'));
    expect($row->status)->toBe('sent')
        ->and($row->fresh()->status)->toBe('sent')
        ->and($laterCalls)->toBe(0);
    Http::assertSentCount(1);
});
