<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Notifications\Channels\SmsChannel;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * T-01 regressions: lifecycle-event isolation and the local
 * persistence-error contract.
 *
 * A consumer listener or a local storage failure must never change an
 * outcome the provider already decided. Persisted state is always read
 * back through the query builder, independently of model events and of
 * the in-memory model the gateway returned.
 */
beforeEach(function (): void {
    Exceptions::fake();
    Http::preventStrayRequests();

    $this->gateway = new TextSmsGateway(
        baseUrl: 'https://sms.textsms.co.ke',
        apiKey: 'test-api-key',
        partnerId: '15682',
        defaultSenderId: 'LIFECYCLE',
        normalizer: new KenyanE164Normalizer,
        logger: app(LoggerInterface::class),
        timeoutSeconds: 5,
    );
});

/** @return array<string, mixed> */
function lifecycleAccepted(int $messageId = 424242, string $description = 'Success'): array
{
    return ['responses' => [[
        'respose-code' => 200,
        'response-description' => $description,
        'messageid' => $messageId,
        'networkid' => '1',
    ]]];
}

/** @return array<string, mixed> */
function lifecycleBulkResponse(string $clientId, int $code, int $messageId): array
{
    return $code === 200
        ? ['respose-code' => 200, 'response-description' => 'Success', 'messageid' => $messageId, 'clientsmsid' => $clientId, 'networkid' => '1']
        : ['respose-code' => $code, 'response-description' => 'Invalid credentials', 'clientsmsid' => $clientId];
}

/**
 * @param  list<string>  $clientIds
 * @return list<array{recipient: string, message: SmsMessage}>
 */
function lifecycleBulkItems(array $clientIds): array
{
    return array_map(
        fn (string $clientId, int $index): array => [
            'recipient' => '071234567'.$index,
            'message' => new SmsMessage(body: "bulk body {$clientId}", clientReference: $clientId),
        ],
        $clientIds,
        array_keys($clientIds),
    );
}

/** @return ArrayObject<int, array{level: string, message: string, context: array<mixed>}> */
function lifecycleLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs): void {
        $logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    return $logs;
}

/** @return list<string> */
function lifecycleStoredStatuses(): array
{
    return DB::table('sms_dispatches')->orderBy('id')->pluck('status')->all();
}

/**
 * Put the real exception handler back (beforeEach installs a fake) and
 * make every report fail, as a broken error-tracker client would.
 */
function lifecycleBrokenReporter(): void
{
    $handler = Exceptions::getFacadeRoot()->handler();
    Exceptions::swap($handler);

    $handler->reportable(function (Throwable $e): void {
        throw new LogicException('reporter down: '.$e->getMessage());
    });
}

/**
 * The recovery entry the gateway logs when a result cannot be recorded.
 *
 * @param  ArrayObject<int, array{level: string, message: string, context: array<mixed>}>  $logs
 * @return array{level: string, message: string, context: array<mixed>}|null
 */
function lifecycleRecoveryEntry(ArrayObject $logs): ?array
{
    return collect($logs)->firstWhere('message', 'TextSmsGateway: dispatch result could not be recorded locally');
}

/**
 * Make every outbound "failed" write a no-op by vetoing it, so the
 * recording stage fails after the provider call.
 */
function lifecycleVetoFailedWrites(): void
{
    SmsDispatch::updating(fn (SmsDispatch $dispatch): ?bool => $dispatch->status === 'failed' ? false : null);
}

// ── Listener failures never change provider outcomes ────────────────────

it('keeps an accepted dispatch sent and returns it when an SmsDispatchAccepted listener throws', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(), 200)]);
    Event::listen(SmsDispatchAccepted::class, fn () => throw new RuntimeException('accepted listener boom'));

    $row = $this->gateway->send('0712345678', new SmsMessage(body: 'x'));

    $stored = DB::table('sms_dispatches')->where('id', $row->id)->first();
    expect($row->status)->toBe('sent')
        ->and($stored->status)->toBe('sent')
        ->and($stored->provider_message_id)->toBe('424242')
        ->and($stored->sent_at)->not->toBeNull()
        ->and($stored->failed_at)->toBeNull();
    Http::assertSentCount(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'accepted listener boom');
    Exceptions::assertReportedCount(1);
});

it('attempts SmsDispatchFailed once and keeps the provider details when a failure listener throws', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response([
        'response-code' => 1003,
        'response-description' => 'Validation Errors. Check errors and try again',
        'errors' => ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']],
    ], 422)]);
    $attempts = 0;
    Event::listen(SmsDispatchFailed::class, function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('failure listener boom');
    });

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x', senderId: 'BAD'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($attempts)->toBe(1)
        ->and($thrown)->not->toBeNull()
        ->and($thrown->providerCode)->toBe('1003')
        ->and($thrown->providerDescription)->toBe('Validation Errors. Check errors and try again')
        ->and($thrown->providerErrors)->toBe(['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']])
        ->and(DB::table('sms_dispatches')->value('response_code'))->toBe('1003');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'failure listener boom');
});

it('keeps the transport cause when a failure listener throws', function (): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::failedConnection('cURL error 7: Failed to connect to sms.textsms.co.ke')]);
    $attempts = 0;
    Event::listen(SmsDispatchFailed::class, function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('failure listener boom');
    });

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($attempts)->toBe(1)
        ->and($thrown)->not->toBeNull()
        ->and($thrown->getPrevious())->toBeInstanceOf(ConnectionException::class)
        ->and(lifecycleStoredStatuses())->toBe(['failed']);
});

it('records every bulk outcome before the first listener runs and keeps publishing after a listener throws', function (string $scenario, array $expectedStatuses, ?string $expectedFailure): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => match ($scenario) {
        'accepted' => Http::response(['responses' => [
            lifecycleBulkResponse('A', 200, 101),
            lifecycleBulkResponse('B', 200, 102),
            lifecycleBulkResponse('C', 200, 103),
        ]], 200),
        'mixed' => Http::response(['responses' => [
            lifecycleBulkResponse('A', 200, 101),
            lifecycleBulkResponse('B', 1006, 0),
            lifecycleBulkResponse('C', 200, 103),
        ]], 200),
        'global rejection' => Http::response([
            'response-code' => 1003,
            'response-description' => 'Validation Errors. Check errors and try again',
            'errors' => ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']],
        ], 422),
        'transport' => Http::failedConnection('cURL error 7: Failed to connect to sms.textsms.co.ke'),
    }]);

    $attempts = [];
    $statusesAtFirstListener = null;
    Event::listen([SmsDispatchAccepted::class, SmsDispatchFailed::class], function (SmsDispatchAccepted|SmsDispatchFailed $event) use (&$attempts, &$statusesAtFirstListener): void {
        $statusesAtFirstListener ??= lifecycleStoredStatuses();
        $attempts[] = $event->dispatch->client_message_id;
        throw new RuntimeException('bulk listener boom');
    });

    $thrown = null;
    try {
        $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B', 'C']));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($attempts)->toBe(['A', 'B', 'C'])
        ->and($statusesAtFirstListener)->toBe($expectedStatuses)
        ->and(lifecycleStoredStatuses())->toBe($expectedStatuses);
    Exceptions::assertReportedCount(3);

    match ($expectedFailure) {
        null => expect($thrown)->toBeNull(),
        'transport' => expect($thrown?->getPrevious())->toBeInstanceOf(ConnectionException::class),
        default => expect($thrown?->providerCode)->toBe($expectedFailure),
    };
})->with([
    'all accepted' => ['accepted', ['sent', 'sent', 'sent'], null],
    'mixed per-row outcomes' => ['mixed', ['sent', 'failed', 'sent'], null],
    'global rejection' => ['global rejection', ['failed', 'failed', 'failed'], '1003'],
    'transport failure' => ['transport', ['failed', 'failed', 'failed'], 'transport'],
]);

it('records a passive report without invoking an SmsDeliveryReported listener', function (): void {
    config()->set('textsms.providers.textsms.dlr_secret', 'lifecycle-secret');
    $row = SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => 'LC-DLR',
        'recipient' => '254712345678',
        'sender_id' => 'LIFECYCLE',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    Event::listen(SmsDeliveryReported::class, fn () => throw new RuntimeException('dlr listener boom'));

    $this->postJson('/api/v1/sms/textsms/dlr?token=lifecycle-secret', ['messageid' => 'LC-DLR', 'status' => 'Delivered'])
        ->assertOk();

    $stored = DB::table('sms_dispatches')->where('id', $row->id)->first();
    expect($stored->status)->toBe('sent')
        ->and($stored->delivered_at)->toBeNull()
        ->and(json_decode($stored->dlr_payload, true)['status'])->toBe('Delivered');
    Exceptions::assertNothingReported();
});

it('stops at the first throwing synchronous listener, which is the documented best-effort limit', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(), 200)]);
    $secondRan = false;
    Event::listen(SmsDispatchAccepted::class, fn () => throw new RuntimeException('first listener boom'));
    Event::listen(SmsDispatchAccepted::class, function () use (&$secondRan): void {
        $secondRan = true;
    });

    $row = $this->gateway->send('0712345678', new SmsMessage(body: 'x'));

    expect($row->status)->toBe('sent')
        ->and($secondRan)->toBeFalse();
    Exceptions::assertReportedCount(1);
});

// ── Local persistence failures are not provider rejections ──────────────

it('reports a local recording failure after acceptance without reclassifying or resending', function (string $modelEvent, string $expectedStored): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(555001), 200)]);
    $storageFailure = new RuntimeException('storage unavailable');
    SmsDispatch::{$modelEvent}(function (SmsDispatch $dispatch) use ($storageFailure): void {
        if ($dispatch->status === 'sent') {
            throw $storageFailure;
        }
    });
    $events = 0;
    Event::listen([SmsDispatchAccepted::class, SmsDispatchFailed::class], function () use (&$events): void {
        $events++;
    });
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'confidential body text', clientReference: 'LC-REC-1'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    $stored = DB::table('sms_dispatches')->sole();
    $recovery = collect($logs)->firstWhere('message', 'TextSmsGateway: dispatch result could not be recorded locally');

    expect($thrown)->not->toBeNull()
        ->and($thrown->getPrevious())->toBe($storageFailure)
        ->and($thrown->getMessage())->toContain('TextSMS accepted dispatch')->toContain('555001')->toContain('unsafe')
        ->and($thrown->providerCode)->toBe('200')
        ->and($stored->status)->toBe($expectedStored)
        ->and($stored->failed_at)->toBeNull()
        ->and($events)->toBe(0)
        ->and($recovery)->not->toBeNull()
        ->and($recovery['level'])->toBe('error')
        ->and($recovery['context']['unrecorded'])->toHaveCount(1)
        ->and($recovery['context']['unrecorded'][0])->toMatchArray([
            'client_message_id' => 'LC-REC-1',
            'provider_outcome' => 'accepted',
            'provider_code' => '200',
            'provider_message_id' => '555001',
        ])
        ->and(json_encode($recovery['context']))
        ->not->toContain('712345678')
        ->not->toContain('confidential body text')
        ->not->toContain('test-api-key');
    Http::assertSentCount(1);
})->with([
    'before the SQL write' => ['updating', 'pending'],
    'after the SQL write' => ['updated', 'sent'],
    'while re-reading the row' => ['retrieved', 'sent'],
]);

it('keeps the provider rejection details when recording the rejection fails', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(
        ['responses' => [['respose-code' => 1004, 'response-description' => 'Low bulk credits']]],
        200,
    )]);
    $storageFailure = new RuntimeException('storage unavailable');
    SmsDispatch::updating(function (SmsDispatch $dispatch) use ($storageFailure): void {
        if ($dispatch->status === 'failed') {
            throw $storageFailure;
        }
    });
    $events = 0;
    Event::listen(SmsDispatchFailed::class, function () use (&$events): void {
        $events++;
    });
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toStartWith('TextSMS rejected dispatch (1004): Low bulk credits. Recording this outcome locally also failed')
        ->and($thrown->getMessage())->not->toContain('accepted')
        ->and($thrown->getMessage())->not->toContain('unsafe')
        ->and($thrown->providerCode)->toBe('1004')
        ->and($thrown->providerDescription)->toBe('Low bulk credits')
        ->and($thrown->getPrevious())->toBe($storageFailure)
        ->and(lifecycleStoredStatuses())->toBe(['pending'])
        ->and($events)->toBe(0)
        ->and(lifecycleRecoveryEntry($logs)['context']['unrecorded'][0]['provider_acceptance'] ?? null)->toBe('rejected');
    Http::assertSentCount(1);
});

it('does not call a batch TextSMS rejected outright unsafe to resend when its recording fails', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => Http::response([
        'response-code' => 1003,
        'response-description' => 'Validation Errors. Check errors and try again',
    ], 422)]);
    SmsDispatch::updating(function (SmsDispatch $dispatch): void {
        if ($dispatch->status === 'failed' && $dispatch->client_message_id === 'B') {
            throw new RuntimeException('storage unavailable for B');
        }
    });
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B']));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('1 of 2')->toContain('TextSMS had rejected the whole batch')
        ->and($thrown->getMessage())->not->toContain('unsafe')
        ->and($thrown->providerCode)->toBe('1003')
        ->and(lifecycleStoredStatuses())->toBe(['failed', 'pending'])
        ->and(lifecycleRecoveryEntry($logs)['context']['unrecorded'][0]['provider_acceptance'] ?? null)->toBe('rejected');
});

// ── Unknown provider output is never presented as a rejection (W1-01) ──

it('calls a batch resend unsafe when an unknown bulk outcome cannot be recorded', function (string $scenario, ?string $expectedProviderCode): void {
    $rows = fn (int|string $code): array => ['responses' => [
        ['respose-code' => $code, 'clientsmsid' => 'A'],
        ['respose-code' => $code, 'clientsmsid' => 'B'],
    ]];

    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => match ($scenario) {
        'unparseable response' => Http::response('<html>upstream page</html>', 200, ['Content-Type' => 'text/html']),
        'responses that match no request' => Http::response(['responses' => [
            lifecycleBulkResponse('X', 200, 901),
            lifecycleBulkResponse('Y', 200, 902),
        ]], 200),
        'corrupted top-level code' => Http::response(['response-code' => 'garbled'], 200),
        'undocumented top-level code' => Http::response(['response-code' => 9999], 200),
        'server-side error top-level code' => Http::response(['response-code' => 4090, 'response-description' => 'Internal Error. Try again after 5 minutes'], 200),
        'corrupted row codes' => Http::response($rows('garbled'), 200),
        'undocumented row codes' => Http::response($rows(9999), 200),
        'non-send row codes' => Http::response($rows(1008), 200),
    }]);
    lifecycleVetoFailedWrites();
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B']));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    $unrecorded = collect(lifecycleRecoveryEntry($logs)['context']['unrecorded'] ?? []);
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('2 of 2')->toContain('may have accepted')->toContain('unsafe')
        ->and($thrown->getMessage())->not->toContain('rejected')
        ->and($thrown->getMessage())->not->toContain('TextSMS accepted')
        ->and($thrown->providerCode)->toBe($expectedProviderCode)
        ->and(lifecycleStoredStatuses())->toBe(['pending', 'pending'])
        ->and($unrecorded->pluck('provider_acceptance')->all())->toBe(['unknown', 'unknown']);
    Http::assertSentCount(1);
})->with([
    'unparseable response' => ['unparseable response', null],
    'responses that match no request' => ['responses that match no request', null],
    // A batch-wide code is kept on the exception as observed, but proves nothing.
    'corrupted top-level code' => ['corrupted top-level code', 'garbled'],
    'undocumented top-level code' => ['undocumented top-level code', '9999'],
    'server-side error top-level code' => ['server-side error top-level code', '4090'],
    'corrupted row codes' => ['corrupted row codes', null],
    'undocumented row codes' => ['undocumented row codes', null],
    'non-send row codes' => ['non-send row codes', null],
]);

it('calls a resend unsafe when an unknown single-send outcome cannot be recorded', function (string $scenario, int $expectedRequests): void {
    Http::fake(['sms.textsms.co.ke/*' => match ($scenario) {
        'unparseable response' => Http::response('<html>upstream page</html>', 200, ['Content-Type' => 'text/html']),
        'transport failure' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received'),
        'row without a code' => Http::response(['responses' => [['response-description' => 'Queued', 'messageid' => 5]]], 200),
        'stray top-level 200' => Http::response(['response-code' => 200, 'response-description' => 'OK'], 200),
        'corrupted row code' => Http::response(['responses' => [['respose-code' => 'garbled']]], 200),
        'undocumented row code' => Http::response(['responses' => [['respose-code' => 9999]]], 200),
        'corrupted top-level code' => Http::response(['response-code' => 'garbled'], 200),
        'undocumented top-level code' => Http::response(['response-code' => 9999], 200),
        'server-side error row code' => Http::response(['responses' => [['respose-code' => 1005, 'response-description' => 'Failed. System error']]], 200),
    }]);
    lifecycleVetoFailedWrites();
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('may have accepted')->toContain('unsafe')
        ->and($thrown->getMessage())->not->toContain('rejected')
        ->and($thrown->getMessage())->not->toContain('TextSMS accepted')
        ->and(lifecycleStoredStatuses())->toBe(['pending'])
        ->and(lifecycleRecoveryEntry($logs)['context']['unrecorded'][0]['provider_acceptance'] ?? null)->toBe('unknown');
    Http::assertSentCount($expectedRequests);
})->with([
    // A context-free transport failure must not trigger a second send.
    'unparseable response' => ['unparseable response', 1],
    'transport failure' => ['transport failure', 1],
    'row without a code' => ['row without a code', 1],
    'stray top-level 200' => ['stray top-level 200', 1],
    'corrupted row code' => ['corrupted row code', 1],
    'undocumented row code' => ['undocumented row code', 1],
    'corrupted top-level code' => ['corrupted top-level code', 1],
    'undocumented top-level code' => ['undocumented top-level code', 1],
    'server-side error row code' => ['server-side error row code', 1],
]);

it('treats every documented send-rejection code as a confirmed rejection', function (int $code): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(
        ['responses' => [['respose-code' => $code, 'response-description' => 'documented rejection']]],
        200,
    )]);
    lifecycleVetoFailedWrites();
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toStartWith("TextSMS rejected dispatch ({$code}): documented rejection. Recording this outcome locally also failed")
        ->not->toContain('unsafe')
        ->and($thrown?->providerCode)->toBe((string) $code)
        ->and(lifecycleRecoveryEntry($logs)['context']['unrecorded'][0]['provider_acceptance'] ?? null)->toBe('rejected');
})->with([1001, 1002, 1003, 1004, 1006, 1009, 1010, 4091, 4092]);

it('decides batch resend safety from every outcome, including rows that were recorded', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => Http::response(['responses' => [
        lifecycleBulkResponse('A', 200, 101),
        ['respose-code' => 'garbled', 'clientsmsid' => 'B'],
        lifecycleBulkResponse('C', 1006, 0),
    ]], 200)]);
    // Only the explicitly rejected row fails to record.
    SmsDispatch::updating(fn (SmsDispatch $dispatch): ?bool => $dispatch->status === 'failed' && $dispatch->client_message_id === 'C' ? false : null);
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B', 'C']));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    $unrecorded = collect(lifecycleRecoveryEntry($logs)['context']['unrecorded'] ?? []);
    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())
        ->toContain('1 of 3')
        ->toContain('resending the batch as a whole is unsafe: TextSMS accepted 1 and may have accepted 1 of the 3 messages')
        ->not->toContain('rejected every dispatch')
        ->and(DB::table('sms_dispatches')->orderBy('id')->pluck('status', 'client_message_id')->all())
        ->toBe(['A' => 'sent', 'B' => 'failed', 'C' => 'pending'])
        ->and($unrecorded->pluck('client_message_id')->all())->toBe(['C'])
        ->and($unrecorded->pluck('provider_acceptance')->all())->toBe(['rejected']);
    Http::assertSentCount(1);
});

// ── Diagnostic failures stay contained (W1-02) ──────────────────────────

it('returns the sent row when the exception reporter itself fails', function (): void {
    lifecycleBrokenReporter();
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(), 200)]);
    Event::listen(SmsDispatchAccepted::class, fn () => throw new RuntimeException('accepted listener boom'));

    $row = $this->gateway->send('0712345678', new SmsMessage(body: 'x'));

    expect($row->status)->toBe('sent')
        ->and(lifecycleStoredStatuses())->toBe(['sent']);
    Http::assertSentCount(1);
});

it('keeps the provider exception when the exception reporter fails during a failure event', function (): void {
    lifecycleBrokenReporter();
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(
        ['responses' => [['respose-code' => 1004, 'response-description' => 'Low bulk credits']]],
        200,
    )]);
    $attempts = 0;
    Event::listen(SmsDispatchFailed::class, function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('failure listener boom');
    });

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(SmsProviderException::class)
        ->and($thrown->providerCode)->toBe('1004')
        ->and($thrown->providerDescription)->toBe('Low bulk credits')
        ->and($attempts)->toBe(1)
        ->and(lifecycleStoredStatuses())->toBe(['failed']);
    Http::assertSentCount(1);
});

it('keeps publishing later bulk events when the exception reporter fails', function (): void {
    lifecycleBrokenReporter();
    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => Http::response(['responses' => [
        lifecycleBulkResponse('A', 200, 101),
        lifecycleBulkResponse('B', 200, 102),
        lifecycleBulkResponse('C', 200, 103),
    ]], 200)]);
    $attempts = [];
    Event::listen(SmsDispatchAccepted::class, function (SmsDispatchAccepted $event) use (&$attempts): void {
        $attempts[] = $event->dispatch->client_message_id;
        throw new RuntimeException('bulk listener boom');
    });

    $rows = $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B', 'C']));

    expect($rows)->toHaveCount(3)
        ->and($attempts)->toBe(['A', 'B', 'C'])
        ->and(lifecycleStoredStatuses())->toBe(['sent', 'sent', 'sent']);
    Http::assertSentCount(1);
});

it('records a passive DLR without invoking delivery listeners or the exception reporter', function (): void {
    lifecycleBrokenReporter();
    config()->set('textsms.providers.textsms.dlr_secret', 'lifecycle-secret');
    SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => 'LC-DLR-REPORTER',
        'recipient' => '254712345678',
        'sender_id' => 'LIFECYCLE',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);
    Event::listen(SmsDeliveryReported::class, fn () => throw new RuntimeException('dlr listener boom'));

    $this->postJson('/api/v1/sms/textsms/dlr?token=lifecycle-secret', ['messageid' => 'LC-DLR-REPORTER', 'status' => 'Delivered'])
        ->assertOk();

    expect(lifecycleStoredStatuses())->toBe(['sent']);
});

it('throws the local-recording exception with its cause when the recovery logger fails', function (): void {
    $failingErrorLogger = new class extends AbstractLogger
    {
        public function log($level, Stringable|string $message, array $context = []): void
        {
            if ($level === LogLevel::ERROR) {
                throw new LogicException('log sink down');
            }
        }
    };
    $gateway = new TextSmsGateway(
        baseUrl: 'https://sms.textsms.co.ke',
        apiKey: 'test-api-key',
        partnerId: '15682',
        defaultSenderId: 'LIFECYCLE',
        normalizer: new KenyanE164Normalizer,
        logger: $failingErrorLogger,
        timeoutSeconds: 5,
    );
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(555004), 200)]);
    $storageFailure = new RuntimeException('storage unavailable');
    SmsDispatch::updating(function (SmsDispatch $dispatch) use ($storageFailure): void {
        if ($dispatch->status === 'sent') {
            throw $storageFailure;
        }
    });

    $thrown = null;
    try {
        $gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $channelResult = (new SmsChannel(sender: $gateway, logger: app(LoggerInterface::class)))->send(
        (new AnonymousNotifiable)->route('sms', '0712345678'),
        new class extends Notification
        {
            public function toSms(object $notifiable): SmsMessage
            {
                return new SmsMessage(body: 'x');
            }
        },
    );

    expect($thrown)->toBeInstanceOf(SmsProviderException::class)
        ->and($thrown->getMessage())->toContain('TextSMS accepted dispatch')->toContain('555004')
        ->and($thrown->providerCode)->toBe('200')
        ->and($thrown->getPrevious())->toBe($storageFailure)
        ->and($channelResult)->toBeNull()
        ->and(lifecycleStoredStatuses())->toBe(['pending', 'pending']);
    Http::assertSentCount(2);
});

it('treats an update cancelled by a model listener as a local recording failure', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(555002), 200)]);
    SmsDispatch::updating(fn (SmsDispatch $dispatch): ?bool => $dispatch->status === 'sent' ? false : null);
    $events = 0;
    Event::listen(SmsDispatchAccepted::class, function () use (&$events): void {
        $events++;
    });

    expect(fn () => $this->gateway->send('0712345678', new SmsMessage(body: 'x')))
        ->toThrow(SmsProviderException::class, 'TextSMS accepted dispatch');

    expect(lifecycleStoredStatuses())->toBe(['pending'])
        ->and($events)->toBe(0);
    Http::assertSentCount(1);
});

it('lets SmsChannel absorb a local recording failure after acceptance', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(lifecycleAccepted(555003), 200)]);
    SmsDispatch::updating(function (SmsDispatch $dispatch): void {
        if ($dispatch->status === 'sent') {
            throw new RuntimeException('storage unavailable');
        }
    });
    $logs = lifecycleLogs();
    $channel = new SmsChannel(sender: $this->gateway, logger: app(LoggerInterface::class));
    $notification = new class extends Notification
    {
        public function toSms(object $notifiable): SmsMessage
        {
            return new SmsMessage(body: 'x');
        }
    };

    $result = $channel->send((new AnonymousNotifiable)->route('sms', '0712345678'), $notification);

    $warning = collect($logs)->firstWhere('message', 'SmsChannel: dispatch failed');
    expect($result)->toBeNull()
        ->and($warning)->not->toBeNull()
        ->and($warning['context']['error'])->toContain('TextSMS accepted dispatch');
    Http::assertSentCount(1);
});

it('reports a local preparation failure without contacting TextSMS', function (string $failure, string $causeClass, int $storedRows): void {
    Http::fake();
    $metadata = [];

    switch ($failure) {
        case 'unencodable metadata':
            $metadata = ['value' => NAN];
            break;
        case 'insert observer throws before the write':
            SmsDispatch::creating(fn () => throw new RuntimeException('storage unavailable'));
            break;
        case 'insert observer cancels the write':
            SmsDispatch::creating(fn (): bool => false);
            break;
        case 'insert observer throws after the write':
            SmsDispatch::created(fn () => throw new RuntimeException('storage unavailable'));
            break;
    }

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x', clientReference: 'LC-PREP-1', metadata: $metadata));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->getMessage())->toContain('no request was sent')->toContain('LC-PREP-1')
        ->and($thrown->getMessage())->not->toContain('accepted')
        ->and($thrown->getPrevious())->toBeInstanceOf($causeClass)
        ->and(DB::table('sms_dispatches')->count())->toBe($storedRows);
    Http::assertNothingSent();
})->with([
    'unencodable metadata' => ['unencodable metadata', JsonEncodingException::class, 0],
    'insert observer throws before the write' => ['insert observer throws before the write', RuntimeException::class, 0],
    'insert observer cancels the write' => ['insert observer cancels the write', RuntimeException::class, 0],
    'insert observer throws after the write' => ['insert observer throws after the write', RuntimeException::class, 1],
]);

it('keeps recording later bulk rows after a middle-row recording failure and accounts for every unrecorded row', function (): void {
    Http::fake(['sms.textsms.co.ke/api/services/sendbulk/' => Http::response(['responses' => [
        lifecycleBulkResponse('A', 200, 101),
        lifecycleBulkResponse('B', 200, 102),
        lifecycleBulkResponse('C', 200, 103),
        lifecycleBulkResponse('D', 200, 104),
    ]], 200)]);
    $firstFailure = new RuntimeException('storage unavailable for B');
    SmsDispatch::updating(function (SmsDispatch $dispatch) use ($firstFailure): void {
        if ($dispatch->status !== 'sent') {
            return;
        }

        match ($dispatch->client_message_id) {
            'B' => throw $firstFailure,
            'D' => throw new RuntimeException('storage unavailable for D'),
            default => null,
        };
    });
    $published = [];
    Event::listen(SmsDispatchAccepted::class, function (SmsDispatchAccepted $event) use (&$published): void {
        $published[] = $event->dispatch->client_message_id;
    });
    $logs = lifecycleLogs();

    $thrown = null;
    try {
        $this->gateway->sendBulk(lifecycleBulkItems(['A', 'B', 'C', 'D']));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    $recovery = collect($logs)->firstWhere('message', 'TextSmsGateway: dispatch result could not be recorded locally');
    $unrecorded = collect($recovery['context']['unrecorded'] ?? []);

    expect(DB::table('sms_dispatches')->orderBy('id')->pluck('status', 'client_message_id')->all())
        ->toBe(['A' => 'sent', 'B' => 'pending', 'C' => 'sent', 'D' => 'pending'])
        ->and($published)->toBe(['A', 'C'])
        ->and($thrown)->not->toBeNull()
        ->and($thrown->getPrevious())->toBe($firstFailure)
        ->and($thrown->getMessage())->toContain('2 of 4')->toContain('102')->toContain('104')->toContain('unsafe')
        ->and($unrecorded->pluck('client_message_id')->all())->toBe(['B', 'D'])
        ->and($unrecorded->pluck('provider_outcome')->all())->toBe(['accepted', 'accepted'])
        ->and($unrecorded->pluck('provider_message_id')->all())->toBe(['102', '104']);
    Http::assertSentCount(1);
});

// ── Stored descriptions fit the 255-character column ────────────────────

it('limits stored descriptions to 255 characters without splitting multibyte text', function (int $providerCode): void {
    $long = str_repeat('aé📨', 120);
    Http::fake(['sms.textsms.co.ke/api/services/sendsms/' => Http::response(['responses' => [[
        'respose-code' => $providerCode,
        'response-description' => $long,
        'messageid' => 777,
        'networkid' => '1',
    ]]], 200)]);

    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x'));
    } catch (SmsProviderException) {
        // the rejected variant throws by design
    }

    $stored = (string) DB::table('sms_dispatches')->value('response_description');
    expect(mb_strlen($stored))->toBe(255)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
        ->and($stored)->toBe(mb_substr($long, 0, 255));
})->with([
    'accepted' => [200],
    'rejected' => [1004],
]);
