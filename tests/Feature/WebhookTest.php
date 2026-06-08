<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Coverage for `POST {prefix}/textsms/dlr` — the only inbound HTTP surface
 * the SDK ships.
 *
 * The webhook is intentionally tolerant: unknown messageids still return
 * 200 so the provider's retry loop doesn't escalate; malformed payloads
 * return 422 so ops sees the bad payload in logs.
 *
 * Auth is a shared secret carried either as a `?token=` query parameter
 * or an `X-TextSMS-Token` header. Constant-time `hash_equals()` comparison.
 */
beforeEach(function (): void {
    Config::set('textsms.providers.textsms.dlr_secret', 'super-secret-dlr-token');
});

it('updates the dispatch row to delivered when a valid DLR arrives', function (): void {
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
    expect($row->status)->toBe('delivered');
    expect($row->delivered_at)->not->toBeNull();
    expect($row->dlr_payload)->toMatchArray(['messageid' => '78726470', 'status' => 'Delivered']);
});

it('updates the dispatch row to failed when DLR reports a failure', function (): void {
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
    expect($row->status)->toBe('failed');
    expect($row->failed_at)->not->toBeNull();
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

it('returns 200 for an unknown messageid so the provider stops retrying', function (): void {
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
