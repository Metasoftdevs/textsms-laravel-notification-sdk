<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use Psr\Log\LoggerInterface;

class PreparedBulkOwner extends Model
{
    protected $table = 'prepared_bulk_owners';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([SmsDispatchAccepted::class, SmsDispatchFailed::class]);
    $this->gateway = new TextSmsGateway(
        baseUrl: 'https://sms.textsms.co.ke',
        apiKey: 'synthetic-key',
        partnerId: 'synthetic-partner',
        defaultSenderId: 'BULK-REVIEW',
        normalizer: new KenyanE164Normalizer,
        logger: app(LoggerInterface::class),
    );
});

/** @return array{recipient: string, message: SmsMessage} */
function preparedBulkItem(string $reference): array
{
    return ['recipient' => '0700000000', 'message' => new SmsMessage('Synthetic bulk body', clientReference: $reference)];
}

/** @return array<string, mixed> */
function preparedBulkReply(string $reference, string|int $messageId): array
{
    return ['clientsmsid' => $reference, 'respose-code' => 200, 'messageid' => $messageId, 'response-description' => 'Success'];
}

/** @return ArrayObject<int, array{message: string, context: array<mixed>}> */
function preparedBulkWarnings(): ArrayObject
{
    $warnings = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = ['message' => $event->message, 'context' => $event->context];
        }
    });

    return $warnings;
}

it('preflights an invalid later item before creating any rows or events', function (mixed $invalid): void {
    $caught = null;
    try {
        $this->gateway->sendBulk([preparedBulkItem('A'), $invalid]);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(SmsDispatchException::class)
        ->and($caught->getMessage())->toContain('1')
        ->and(SmsDispatch::count())->toBe(0);
    Http::assertNothingSent();
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Event::assertNotDispatched(SmsDispatchFailed::class);
})->with([
    'non-array' => [null],
    'missing recipient' => [fn () => ['message' => new SmsMessage('x')]],
    'numeric recipient' => [fn () => ['recipient' => 700000000, 'message' => new SmsMessage('x')]],
    'invalid recipient' => [fn () => ['recipient' => 'invalid', 'message' => new SmsMessage('x')]],
    'missing message' => [['recipient' => '0700000000']],
    'wrong message' => [['recipient' => '0700000000', 'message' => 'x']],
    'blank body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage('  ')]],
    'invalid notifiable' => [fn () => preparedBulkItem('B') + ['notifiable' => false]],
    'duplicate reference' => [fn () => preparedBulkItem('A')],
    'blank reference' => [fn () => preparedBulkItem('  ')],
    'invalid UTF-8 body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage("bad\xB1")]],
    'invalid UTF-8 reference' => [fn () => preparedBulkItem("bad\xB1")],
    'invalid UTF-8 sender' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage('x', senderId: "bad\xB1")]],
]);

it('keeps an empty bulk call a no-op and accepts exactly twenty normalized items', function (): void {
    expect($this->gateway->sendBulk([]))->toHaveCount(0);
    Http::assertNothingSent();
    Http::fake(['sms.textsms.co.ke/*' => function ($request) {
        return Http::response(['responses' => array_map(
            fn (array $item): array => preparedBulkReply($item['clientsmsid'], 'provider-'.$item['clientsmsid']),
            $request->data()['smslist'],
        )]);
    }]);

    $rows = $this->gateway->sendBulk(array_map(fn (int $i): array => preparedBulkItem('R'.$i), range(1, 20)));

    expect($rows)->toHaveCount(20)
        ->and($rows->pluck('status')->unique()->all())->toBe(['sent'])
        ->and($rows->pluck('recipient')->unique()->all())->toBe(['254700000000']);
    Http::assertSentCount(1);
});

it('rolls back every prepared row if a later insert fails on the model connection', function (bool $alternate): void {
    if ($alternate) {
        config()->set('database.connections.sms_alternate', config('database.connections.testing'));
        config()->set('textsms.database.connection', 'sms_alternate');
        $migration = require __DIR__.'/../../database/migrations/2026_06_08_000000_create_sms_dispatches_table.php';
        $migration->up();
    }
    $cause = new RuntimeException('Synthetic second insert failure');
    SmsDispatch::creating(function (SmsDispatch $row) use ($cause): void {
        if ($row->client_message_id === 'B') {
            throw $cause;
        }
    });

    expect(fn () => $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B')]))
        ->toThrow(SmsProviderException::class, 'no request was sent');
    expect(SmsDispatch::count())->toBe(0)
        ->and(DB::connection('testing')->table('sms_dispatches')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['default connection' => [false], 'alternate model connection' => [true]]);

it('finishes the insertion transaction before calling the provider', function (): void {
    $connection = (new SmsDispatch)->getConnection();
    $initialLevel = $connection->transactionLevel();
    Http::fake(['sms.textsms.co.ke/*' => function () use ($connection, $initialLevel) {
        expect($connection->transactionLevel())->toBe($initialLevel)
            ->and(SmsDispatch::pluck('status')->all())->toBe(['pending', 'pending']);

        return Http::response(['responses' => [preparedBulkReply('A', 101), preparedBulkReply('B', 102)]]);
    }]);

    $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B')]);
    Http::assertSentCount(1);
});

it('matches reordered responses while preserving input order and valid notifiable ownership', function (): void {
    Schema::create('prepared_bulk_owners', fn (Blueprint $table) => $table->id());
    $owner = PreparedBulkOwner::create(['id' => 73]);
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [preparedBulkReply('B', 102), preparedBulkReply('A', 101)]])]);

    $rows = $this->gateway->sendBulk([7 => preparedBulkItem('A') + ['notifiable' => $owner], 19 => preparedBulkItem('B')]);

    expect($rows->keys()->all())->toBe([0, 1])
        ->and($rows->pluck('client_message_id')->all())->toBe(['A', 'B'])
        ->and($rows->pluck('provider_message_id')->all())->toBe(['101', '102'])
        ->and($rows[0]->notifiable_id)->toBe(73);
    Http::assertSentCount(1);
});

it('records uncertain correlation without discarding valid siblings', function (array $responses, array $statuses, array $sources): void {
    $warnings = preparedBulkWarnings();
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => $responses])]);

    $rows = $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B')]);

    expect($rows->pluck('status')->all())->toBe($statuses)
        ->and($warnings)->toHaveCount(1)
        ->and(json_encode($warnings->getArrayCopy()))->not->toContain('synthetic-key', 'synthetic-partner', 'Synthetic bulk body', '0700000000');
    foreach ($sources as $index => $source) {
        $diagnostics = $rows[$index]->metadata['provider_diagnostics'] ?? [];
        expect($diagnostics['parse_source'] ?? null)->toBe($source)
            ->and($diagnostics['provider_acceptance'] ?? null)->toBe('unknown')
            ->and($rows[$index]->provider_message_id)->toBeNull();
    }
    Http::assertSentCount(1);
})->with([
    'missing response' => [fn () => [preparedBulkReply('A', 101)], ['sent', 'failed'], [1 => 'unmatched']],
    'duplicate response reference' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('A', 103), preparedBulkReply('B', 102)], ['failed', 'sent'], [0 => 'duplicate-client-id']],
    'duplicate provider id' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('B', 101)], ['failed', 'failed'], [0 => 'duplicate-provider-id', 1 => 'duplicate-provider-id']],
    'duplicate provider id in duplicate client rows' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('A', 103), preparedBulkReply('B', 101)], ['failed', 'failed'], [0 => 'duplicate-client-id', 1 => 'duplicate-provider-id']],
    'duplicate provider id in malformed row' => [fn () => [preparedBulkReply('A', 101) + ['networkid' => []], preparedBulkReply('B', 101)], ['failed', 'failed'], [0 => 'malformed-response', 1 => 'duplicate-provider-id']],
    'duplicate provider id in unexpected row' => [fn () => [preparedBulkReply('X', 101), preparedBulkReply('A', 101), preparedBulkReply('B', 102)], ['failed', 'sent'], [0 => 'duplicate-provider-id']],
    'unexpected response reference' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('X', 103)], ['sent', 'failed'], [1 => 'unmatched']],
    'malformed uncorrelated entry' => [fn () => [preparedBulkReply('A', 101), false], ['sent', 'failed'], [1 => 'unmatched']],
    'malformed correlated code' => [fn () => [preparedBulkReply('A', 101), ['clientsmsid' => 'B', 'respose-code' => [200]]], ['sent', 'failed'], [1 => 'malformed-response']],
    'malformed correlated network' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('B', 102) + ['networkid' => []]], ['sent', 'failed'], [1 => 'malformed-response']],
    'conflicting code aliases' => [fn () => [preparedBulkReply('A', 101), preparedBulkReply('B', 102) + ['response-code' => 1004]], ['sent', 'failed'], [1 => 'malformed-response']],
]);

it('warns about an unexpected extra response without invalidating complete valid mappings', function (): void {
    $warnings = preparedBulkWarnings();
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [preparedBulkReply('A', 101), preparedBulkReply('B', 102), preparedBulkReply('X', 103)]])]);

    $rows = $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B')]);

    expect($rows->pluck('status')->all())->toBe(['sent', 'sent'])
        ->and($warnings)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('keeps code 200 without a usable provider id uncertain for single and bulk calls', function (mixed $providerId, bool $bulk): void {
    $payload = ['respose-code' => 200, 'messageid' => $providerId, 'clientsmsid' => 'A'];
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [$payload]])]);
    if ($bulk) {
        $row = $this->gateway->sendBulk([preparedBulkItem('A')])[0];
    } else {
        expect(fn () => $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A')))
            ->toThrow(SmsProviderException::class, 'unsafe');
        $row = SmsDispatch::firstOrFail();
    }

    expect($row->status)->toBe('failed')
        ->and($row->provider_message_id)->toBeNull()
        ->and($row->response_code)->toBe('200')
        ->and($row->metadata['provider_diagnostics']['parse_source'] ?? null)->toBe('invalid-message-id')
        ->and($row->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('unknown');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Event::assertDispatchedTimes(SmsDispatchFailed::class, 1);
    Http::assertSentCount(1);
})->with([
    'single missing id' => [null, false],
    'single blank id' => [' ', false],
    'single array id' => [[], false],
    'bulk missing id' => [null, true],
    'bulk bool id' => [true, true],
]);

it('accepts a zero provider message id without treating it as missing', function (): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [preparedBulkReply('A', 0)]])]);
    $row = $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A'));
    expect($row->status)->toBe('sent')->and($row->provider_message_id)->toBe('0');
});

it('does not accept a successful-looking message on an HTTP error response', function (bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [preparedBulkReply('A', 101)]], 500)]);
    if ($bulk) {
        $row = $this->gateway->sendBulk([preparedBulkItem('A')])[0];
    } else {
        expect(fn () => $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A')))
            ->toThrow(SmsProviderException::class, 'unsafe');
        $row = SmsDispatch::firstOrFail();
    }
    expect($row->status)->toBe('failed')
        ->and($row->metadata['provider_diagnostics']['parse_source'] ?? null)->toBe('http-error')
        ->and($row->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('unknown');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Http::assertSentCount(1);
})->with(['single' => [false], 'bulk' => [true]]);

it('uses truthful normal exceptions for unknown global outcomes', function (bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['response-code' => 'garbled'])]);
    $caught = null;
    try {
        $bulk
            ? $this->gateway->sendBulk([preparedBulkItem('A')])
            : $this->gateway->send('0700000000', new SmsMessage('Synthetic'));
    } catch (SmsProviderException $e) {
        $caught = $e;
    }
    expect($caught)->not->toBeNull()
        ->and($caught->getMessage())->toContain('may have accepted')->toContain('unsafe')
        ->and($caught->getMessage())->not->toContain('rejected')
        ->and($caught->providerCode)->toBe('garbled')
        ->and(SmsDispatch::firstOrFail()->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('unknown');
})->with(['single' => [false], 'bulk' => [true]]);

it('does not accept a response that contradicts its top-level envelope', function (array $envelope, bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response($envelope + ['responses' => [preparedBulkReply('A', 101)]])]);

    expect(fn () => $bulk
        ? $this->gateway->sendBulk([preparedBulkItem('A')])
        : $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A')))
        ->toThrow(SmsProviderException::class, 'unsafe');

    $row = SmsDispatch::firstOrFail();
    expect($row->status)->toBe('failed')
        ->and($row->provider_message_id)->toBeNull()
        ->and($row->metadata['provider_diagnostics']['parse_source'] ?? null)->toBe('malformed-response')
        ->and($row->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('unknown');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Http::assertSentCount(1);
})->with([
    'rejection' => [['response-code' => 1004]],
    'unknown code' => [['response-code' => 'garbled']],
    'malformed code' => [['response-code' => []]],
    'contradictory errors' => [['response-code' => 200, 'errors' => ['message' => ['invalid']]]],
])->with(['single' => [false], 'bulk' => [true]]);

it('retains independently valid siblings when other response claims collide', function (): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['responses' => [
        preparedBulkReply('A', 101), preparedBulkReply('A', 103),
        preparedBulkReply('B', 101), preparedBulkReply('C', 104),
    ]])]);

    $rows = $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B'), preparedBulkItem('C')]);
    expect($rows->pluck('status')->all())->toBe(['failed', 'failed', 'sent'])
        ->and($rows[2]->provider_message_id)->toBe('104');
    Event::assertDispatchedTimes(SmsDispatchAccepted::class, 1);
    Http::assertSentCount(1);
});

it('allows a successful call envelope to contain mixed per-message outcomes', function (): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['response-code' => 200, 'responses' => [
        preparedBulkReply('A', 101), ['clientsmsid' => 'B', 'respose-code' => 1004],
    ]])]);
    $rows = $this->gateway->sendBulk([preparedBulkItem('A'), preparedBulkItem('B')]);
    expect($rows->pluck('status')->all())->toBe(['sent', 'failed']);
});

it('does not infer rejection from a malformed response collection', function (mixed $responses, bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['response-code' => 1004, 'responses' => $responses])]);

    expect(fn () => $bulk
        ? $this->gateway->sendBulk([preparedBulkItem('A')])
        : $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A')))
        ->toThrow(SmsProviderException::class, 'unsafe');

    $row = SmsDispatch::firstOrFail();
    expect($row->status)->toBe('failed')
        ->and($row->provider_message_id)->toBeNull()
        ->and($row->metadata['provider_diagnostics']['parse_source'] ?? null)->toBe('malformed-response')
        ->and($row->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('unknown');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Http::assertSentCount(1);
})->with([
    'scalar string' => ['garbled'],
    'scalar bool' => [false],
    'associative success' => [['A' => preparedBulkReply('A', 101)]],
    'one-based success object' => [[1 => preparedBulkReply('A', 101)]],
])->with(['single' => [false], 'bulk' => [true]]);

it('keeps malformed collection recovery uncertain when recording is cancelled', function (bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response([
        'response-code' => 1004, 'responses' => ['A' => preparedBulkReply('A', 101)],
    ])]);
    SmsDispatch::updating(fn (): bool => false);
    $caught = null;
    try {
        $bulk
            ? $this->gateway->sendBulk([preparedBulkItem('A')])
            : $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A'));
    } catch (SmsProviderException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(SmsProviderException::class)
        ->and($caught->getMessage())->toContain('may have accepted', 'unsafe')
        ->and($caught->getMessage())->not->toContain('TextSMS rejected')
        ->and(SmsDispatch::firstOrFail()->status)->toBe('pending');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Event::assertNotDispatched(SmsDispatchFailed::class);
    Http::assertSentCount(1);
})->with(['single' => [false], 'bulk' => [true]]);

it('preserves explicit global refusal with absent null or empty responses', function (array $envelope, bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['response-code' => 1004] + $envelope)]);

    expect(fn () => $bulk
        ? $this->gateway->sendBulk([preparedBulkItem('A')])
        : $this->gateway->send('0700000000', new SmsMessage('Synthetic', clientReference: 'A')))
        ->toThrow(SmsProviderException::class, 'rejected');
    expect(SmsDispatch::firstOrFail()->metadata['provider_diagnostics']['provider_acceptance'] ?? null)->toBe('rejected');
    Http::assertSentCount(1);
})->with([
    'absent' => [[]],
    'null' => [['responses' => null]],
    'empty list' => [['responses' => []]],
    'empty object' => [['responses' => new stdClass]],
])->with(['single' => [false], 'bulk' => [true]]);
