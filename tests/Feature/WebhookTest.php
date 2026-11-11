<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\LogOnlySmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Coverage for `POST {prefix}/textsms/dlr` — the only inbound HTTP surface
 * the SDK ships.
 *
 * The webhook is intentionally tolerant: unknown messageids still return
 * 200 by SDK policy; provider retry behavior is unverified. Malformed payloads
 * return 422 so ops sees the bad payload in logs.
 *
 * Auth is a shared secret carried either as a `?token=` query parameter
 * or an `X-TextSMS-Token` header. Constant-time `hash_equals()` comparison.
 */
beforeEach(function (): void {
    Config::set('textsms.providers.textsms.dlr_secret', 'super-secret-dlr-token');
});

/**
 * Record every log entry written through Laravel's logger during the test.
 *
 * @return ArrayObject<int, array{level: string, message: string, context: array<mixed>}>
 */
function captureWebhookLogs(): ArrayObject
{
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs): void {
        $logs[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
    });

    return $logs;
}

/**
 * Record the payload of every SmsDeliveryReported event without faking
 * other events (model hooks such as the ULID generator keep running).
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function captureDeliveryEvents(): ArrayObject
{
    $payloads = new ArrayObject;

    Event::listen(SmsDeliveryReported::class, function (SmsDeliveryReported $event) use ($payloads): void {
        $payloads[] = $event->payload;
    });

    return $payloads;
}

function sentDispatch(string $providerMessageId): SmsDispatch
{
    return SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => $providerMessageId,
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);
}

it('records a delivered callback as information without changing submission state', function (): void {
    $row = SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => '78726470',
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'test',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $response = $this->postJson(
        '/api/v1/sms/textsms/dlr?token=super-secret-dlr-token',
        [
            'messageid' => '78726470',
            'mobile' => '254712345678',
            'status' => 'Delivered',
        ],
    );

    $response->assertOk()->assertJson(['status' => 200, 'message' => 'OK']);

    $row->refresh();
    expect($row->status)->toBe('sent');
    expect($row->delivered_at)->toBeNull();
    expect($row->dlr_payload)->toMatchArray(['messageid' => '78726470', 'status' => 'Delivered']);
});

it('records a failed callback as information without changing submission state', function (): void {
    $row = SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => '78726471',
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'test',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $response = $this->postJson(
        '/api/v1/sms/textsms/dlr?token=super-secret-dlr-token',
        [
            'messageid' => '78726471',
            'status' => 'Failed',
        ],
    );

    $response->assertOk();

    $row->refresh();
    expect($row->status)->toBe('sent');
    expect($row->failed_at)->toBeNull();
});

it('returns 401 when the token is missing', function (): void {
    $this->postJson('/api/v1/sms/textsms/dlr', ['messageid' => '1', 'status' => 'Delivered'])
        ->assertStatus(401);
});

it('returns 401 when the token is wrong', function (): void {
    $this->postJson('/api/v1/sms/textsms/dlr?token=WRONG', ['messageid' => '1', 'status' => 'Delivered'])
        ->assertStatus(401);
});

it('accepts the token via X-TextSMS-Token header', function (): void {
    SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => 'HEADER-1',
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->withHeaders(['X-TextSMS-Token' => 'super-secret-dlr-token'])
        ->postJson('/api/v1/sms/textsms/dlr', ['messageid' => 'HEADER-1', 'status' => 'Delivered'])
        ->assertOk();
});

it('acknowledges an unknown messageid without claiming a matched record', function (): void {
    $this->postJson(
        '/api/v1/sms/textsms/dlr?token=super-secret-dlr-token',
        ['messageid' => 'never-existed', 'status' => 'Delivered'],
    )->assertOk();
});

it('returns 422 when messageid is missing from the payload', function (): void {
    $this->postJson(
        '/api/v1/sms/textsms/dlr?token=super-secret-dlr-token',
        ['status' => 'Delivered'],
    )->assertStatus(422);
});

it('returns 500 when TEXTSMS_DLR_SECRET is unconfigured', function (): void {
    Config::set('textsms.providers.textsms.dlr_secret', '');

    $this->postJson(
        '/api/v1/sms/textsms/dlr?token=anything',
        ['messageid' => '1', 'status' => 'Delivered'],
    )->assertStatus(500);
});

// ── T-05: secret hygiene ────────────────────────────────────────────────

it('strips the query token before passive storage and emits no delivery event', function (): void {
    $row = sentDispatch('T05-QUERY');
    $events = captureDeliveryEvents();

    $this->postJson(
        '/api/v1/sms/textsms/dlr?token=super-secret-dlr-token',
        ['messageid' => 'T05-QUERY', 'status' => 'Delivered'],
    )->assertOk();

    $row->refresh();
    expect($row->status)->toBe('sent')
        ->and($row->dlr_payload)->toBe(['messageid' => 'T05-QUERY', 'status' => 'Delivered'])
        ->and($events)->toHaveCount(0);
});

it('strips a token carried in the body even when authentication used the header', function (): void {
    $row = sentDispatch('T05-BODY');
    $events = captureDeliveryEvents();

    $this->withHeaders(['X-TextSMS-Token' => 'super-secret-dlr-token'])
        ->postJson('/api/v1/sms/textsms/dlr', [
            'messageid' => 'T05-BODY',
            'status' => 'Delivered',
            'token' => 'super-secret-dlr-token',
        ])
        ->assertOk();

    expect($row->refresh()->dlr_payload)->not->toHaveKey('token')
        ->and($events)->toHaveCount(0);
});

it('authenticates via header and accepts the alternate messageID and dlrstatus keys', function (): void {
    $row = sentDispatch('T05-ALT');

    $this->withHeaders(['X-TextSMS-Token' => 'super-secret-dlr-token'])
        ->postJson('/api/v1/sms/textsms/dlr', ['messageID' => 'T05-ALT', 'dlrstatus' => 'DELIVRD'])
        ->assertOk();

    $row->refresh();
    expect($row->status)->toBe('sent')
        ->and($row->dlr_payload)->toBe(['messageID' => 'T05-ALT', 'dlrstatus' => 'DELIVRD']);
});

it('keeps the presented token out of the log on the invalid-token denial branch', function (): void {
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=stale-but-real-old-secret', ['messageid' => '1'])
        ->assertStatus(401);

    expect($logs)->toHaveCount(1)
        ->and(json_encode($logs->getArrayCopy()))->not->toContain('stale-but-real-old-secret')
        ->and($logs[0]['context']['query_keys'])->toBe(['token']);
});

it('keeps the presented token out of the log on the unconfigured-secret denial branch', function (): void {
    Config::set('textsms.providers.textsms.dlr_secret', '');
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=presented-before-configuration', ['messageid' => '1'])
        ->assertStatus(500);

    expect($logs)->toHaveCount(1)
        ->and(json_encode($logs->getArrayCopy()))->not->toContain('presented-before-configuration');
});

it('rejects a non-string query token with 401 and changes nothing', function (): void {
    $row = sentDispatch('T05-TOKEN-ARRAY');
    $events = captureDeliveryEvents();

    $this->postJson('/api/v1/sms/textsms/dlr?token[]=super-secret-dlr-token', ['messageid' => 'T05-TOKEN-ARRAY', 'status' => 'Delivered'])
        ->assertStatus(401);

    expect($row->refresh()->status)->toBe('sent')
        ->and($row->dlr_payload)->toBeNull()
        ->and($events)->toHaveCount(0);
});

it('gives the header precedence over the query token', function (): void {
    $this->withHeaders(['X-TextSMS-Token' => 'wrong-header-value'])
        ->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => '1'])
        ->assertStatus(401);
});

// ── T-05: authenticated payload shape validation ────────────────────────

it('rejects a malformed message id with 422, one keys-only warning, and no side effects', function (array $payload, string $expectedMessage): void {
    $row = sentDispatch('T05-UNTOUCHED');
    $events = captureDeliveryEvents();
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', $payload)
        ->assertStatus(422)
        ->assertJson(['status' => 422, 'message' => $expectedMessage]);

    expect($row->refresh()->status)->toBe('sent')
        ->and($row->dlr_payload)->toBeNull()
        ->and(SmsDispatch::query()->count())->toBe(1)
        ->and($events)->toHaveCount(0)
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]['level'])->toBe('warning')
        ->and(array_keys($logs[0]['context']))->toBe(['reason', 'payload_keys'])
        ->and($logs[0]['context']['payload_keys'])->toBe(array_keys($payload));
})->with([
    'missing id' => [['status' => 'Delivered'], 'Missing messageid in payload'],
    'blank id' => [['messageid' => '   ', 'status' => 'Delivered'], 'Missing messageid in payload'],
    'array id' => [['messageid' => ['T05-UNTOUCHED'], 'status' => 'Delivered'], 'Invalid messageid in payload'],
    'object id' => [['messageid' => ['id' => 'T05-UNTOUCHED'], 'status' => 'Delivered'], 'Invalid messageid in payload'],
    'boolean id' => [['messageid' => true, 'status' => 'Delivered'], 'Invalid messageid in payload'],
    'float id' => [['messageid' => 1.5, 'status' => 'Delivered'], 'Invalid messageid in payload'],
    'array alternate id' => [['messageID' => ['T05-UNTOUCHED'], 'status' => 'Delivered'], 'Invalid messageid in payload'],
]);

it('rejects a malformed status with 422 and leaves the matched row untouched', function (array $statusFields): void {
    $row = sentDispatch('T05-STATUS');
    $events = captureDeliveryEvents();
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => 'T05-STATUS'] + $statusFields)
        ->assertStatus(422)
        ->assertJson(['message' => 'Invalid status in payload']);

    expect($row->refresh()->status)->toBe('sent')
        ->and($row->dlr_payload)->toBeNull()
        ->and($events)->toHaveCount(0)
        ->and($logs)->toHaveCount(1)
        ->and(array_keys($logs[0]['context']))->toBe(['reason', 'payload_keys']);
})->with([
    'array status' => [['status' => ['Delivered']]],
    'boolean status' => [['status' => true]],
    'object alternate status' => [['dlrstatus' => ['state' => 'DELIVRD']]],
]);

it('accepts zero-valued message ids and routes them through the ordinary lookup', function (int|string $messageId): void {
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => $messageId, 'status' => 'Delivered'])
        ->assertOk();

    expect(collect($logs)->pluck('message')->all())->toBe(['TextSmsGateway: DLR for unknown messageid']);
})->with([
    'integer zero' => [0],
    'string zero' => ['0'],
]);

it('accepts an integer provider message id', function (): void {
    $row = sentDispatch('78726470');

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => 78726470, 'status' => 'Delivered'])
        ->assertOk();

    expect($row->refresh()->status)->toBe('sent');
});

it('records an absent status as passive information', function (): void {
    $row = sentDispatch('T05-NO-STATUS');
    $events = captureDeliveryEvents();

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => 'T05-NO-STATUS'])
        ->assertOk();

    expect($row->refresh()->status)->toBe('sent')
        ->and($row->dlr_payload)->toBe(['messageid' => 'T05-NO-STATUS'])
        ->and($events)->toHaveCount(0);
});

// ── T-05: ingress sanitization covers every bound processor ─────────────

it('hands the LogOnly processor a token-free payload through the HTTP callback', function (): void {
    Config::set('textsms.default_sender', LogOnlySmsGateway::class);
    $logs = captureWebhookLogs();

    $this->postJson('/api/v1/sms/textsms/dlr?token=super-secret-dlr-token', ['messageid' => 'T05-LOG-ONLY', 'status' => 'Delivered'])
        ->assertOk();

    $ignored = collect($logs)->firstWhere('message', 'LogOnlySmsGateway: DLR ignored');

    expect($ignored)->not->toBeNull()
        ->and($ignored['context']['payload'])->toBe(['messageid' => 'T05-LOG-ONLY', 'status' => 'Delivered'])
        ->and(json_encode($logs->getArrayCopy()))->not->toContain('super-secret-dlr-token');
});
