<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use Psr\Log\NullLogger;

/**
 * Feature suite for the TextSMS provider adapter. The 15 cases ported from
 * the QPIMS benchmark cover every known response shape (A, B, C) for both
 * single-send and bulk, plus DLR happy / failed / unknown classification.
 *
 * Two additional cases prove the event-extraction performed during SDK
 * extraction (Reflection §3.1):
 *
 *  - `SmsDispatchAccepted` is fired on a successful send.
 *  - `SmsDispatchFailed` carries the structured `providerErrors` payload.
 */
beforeEach(function (): void {
    $this->gateway = new TextSmsGateway(
        baseUrl: 'https://sms.textsms.co.ke',
        apiKey: 'test-api-key',
        partnerId: '15682',
        defaultSenderId: 'QPIVL',
        normalizer: new KenyanE164Normalizer,
        logger: new NullLogger,
        timeoutSeconds: 5,
    );
});

it('sends an SMS, persists a row, and matches the documented payload shape', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'responses' => [[
                'respose-code' => 200,
                'response-description' => 'Success',
                'mobile' => 254712345678,
                'messageid' => 78726470,
                'networkid' => '1',
            ]],
        ], 200),
    ]);

    $row = $this->gateway->send(
        recipient: '0712345678',
        message: new SmsMessage(body: 'Test from QPIVL'),
    );

    expect($row)->toBeInstanceOf(SmsDispatch::class);
    expect($row->status)->toBe('sent');
    expect($row->recipient)->toBe('254712345678');
    expect($row->sender_id)->toBe('QPIVL');
    expect($row->provider)->toBe('textsms');
    expect($row->provider_message_id)->toBe('78726470');
    expect($row->network_id)->toBe('1');
    expect($row->response_code)->toBe('200');
    expect($row->sent_at)->not->toBeNull();

    Http::assertSent(function ($req) {
        $body = $req->data();

        return $req->url() === 'https://sms.textsms.co.ke/api/services/sendsms/'
            && $body['apikey'] === 'test-api-key'
            && $body['partnerID'] === '15682'
            && $body['mobile'] === '254712345678'
            && $body['shortcode'] === 'QPIVL'
            && $body['pass_type'] === 'plain'
            && $body['message'] === 'Test from QPIVL'
            && ! empty($body['clientsmsid']);
    });
});

it('marks the row failed and throws SmsProviderException on low-credit response 1004', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'responses' => [[
                'respose-code' => 1004,
                'response-description' => 'Low bulk credits',
            ]],
        ], 200),
    ]);

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'Hello'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->providerCode)->toBe('1004');
    expect($thrown->providerDescription)->toBe('Low bulk credits');

    $row = SmsDispatch::query()->latest('id')->first();
    expect($row->status)->toBe('failed');
    expect($row->response_code)->toBe('1004');
    expect($row->failed_at)->not->toBeNull();
    expect($row->sent_at)->toBeNull();
});

it('throws SmsDispatchException for invalid phone numbers without making an HTTP call', function (): void {
    Http::fake();

    expect(fn () => $this->gateway->send('not-a-phone', new SmsMessage(body: 'Hi')))
        ->toThrow(SmsDispatchException::class);

    Http::assertNothingSent();
    expect(SmsDispatch::count())->toBe(0);
});

it('throws SmsDispatchException for empty body without making an HTTP call', function (): void {
    Http::fake();

    expect(fn () => $this->gateway->send('0712345678', new SmsMessage(body: '   ')))
        ->toThrow(SmsDispatchException::class);

    Http::assertNothingSent();
});

it('uses the per-message senderId override when one is supplied', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'responses' => [['respose-code' => 200, 'response-description' => 'Success', 'messageid' => 1, 'networkid' => '1']],
        ], 200),
    ]);

    $this->gateway->send(
        recipient: '0712345678',
        message: new SmsMessage(body: 'override test', senderId: 'CUSTOM'),
    );

    Http::assertSent(fn ($req) => $req->data()['shortcode'] === 'CUSTOM');
});

it('sends bulk and matches each clientsmsid back to the right row', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendbulk/' => Http::response([
            'responses' => [
                ['respose-code' => 200, 'response-description' => 'Success', 'mobile' => '254712345678', 'messageid' => 1001, 'clientsmsid' => 'ABC', 'networkid' => '1'],
                ['respose-code' => 1006, 'response-description' => 'Invalid credentials', 'clientsmsid' => 'XYZ'],
            ],
        ], 200),
    ]);

    $rows = $this->gateway->sendBulk([
        ['recipient' => '0712345678', 'message' => new SmsMessage(body: 'one', clientReference: 'ABC')],
        ['recipient' => '0712999999', 'message' => new SmsMessage(body: 'two', clientReference: 'XYZ')],
    ]);

    expect($rows)->toHaveCount(2);

    $a = SmsDispatch::query()->where('client_message_id', 'ABC')->first();
    $b = SmsDispatch::query()->where('client_message_id', 'XYZ')->first();

    expect($a->status)->toBe('sent');
    expect($a->provider_message_id)->toBe('1001');

    expect($b->status)->toBe('failed');
    expect($b->response_code)->toBe('1006');

    Http::assertSent(fn ($req) => $req->url() === 'https://sms.textsms.co.ke/api/services/sendbulk/'
        && $req->data()['count'] === 2
        && count($req->data()['smslist']) === 2);
});

it('rejects bulk over the 20-message provider limit', function (): void {
    Http::fake();

    $items = [];
    for ($i = 0; $i < 21; $i++) {
        $items[] = ['recipient' => '0712345678', 'message' => new SmsMessage(body: "msg {$i}")];
    }

    expect(fn () => $this->gateway->sendBulk($items))->toThrow(SmsDispatchException::class);
    Http::assertNothingSent();
});

it('stores a Delivered callback without changing submission state', function (): void {
    Http::fake();

    $row = SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => '999',
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $updated = $this->gateway->recordDeliveryReport([
        'messageid' => '999',
        'status' => 'Delivered',
        'mobile' => '254712345678',
    ]);

    expect($updated->id)->toBe($row->id);
    expect($updated->status)->toBe('sent');
    expect($updated->delivered_at)->toBeNull();
    expect($updated->dlr_payload['status'])->toBe('Delivered');
});

it('stores an Undelivered callback without classifying it or firing a delivery event', function (): void {
    Event::fake([SmsDeliveryReported::class]);

    SmsDispatch::create([
        'provider' => 'textsms',
        'provider_message_id' => '888',
        'recipient' => '254712345678',
        'sender_id' => 'QPIVL',
        'body' => 'x',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $updated = $this->gateway->recordDeliveryReport([
        'messageid' => '888',
        'status' => 'Undelivered',
        'mobile' => '254712345678',
    ]);

    expect($updated->status)->toBe('sent');
    expect($updated->failed_at)->toBeNull();
    expect($updated->dlr_payload['status'])->toBe('Undelivered');
    Event::assertNotDispatched(SmsDeliveryReported::class);
});

it('returns null from recordDeliveryReport when messageid is unknown', function (): void {
    expect($this->gateway->recordDeliveryReport(['messageid' => 'unknown-999', 'status' => 'Delivered']))->toBeNull();
});

it('hits the balance endpoint with correct credentials', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/getbalance/' => Http::response(['credit' => 1234.56], 200),
    ]);

    $result = $this->gateway->balance();

    expect($result)->toBe(['credit' => 1234.56]);
    Http::assertSent(fn ($req) => $req->url() === 'https://sms.textsms.co.ke/api/services/getbalance/'
        && $req->data()['apikey'] === 'test-api-key'
        && $req->data()['partnerID'] === '15682');
});

it('parses TextSMS 422 validation-error shape, persists structured diagnostics, and throws with errors', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'response-code' => 1003,
            'response-description' => 'Validation Errors. Check errors and try again',
            'errors' => ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']],
        ], 422),
    ]);

    $thrown = null;
    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x', senderId: 'BAD'));
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->providerCode)->toBe('1003');
    expect($thrown->providerDescription)->toBe('Validation Errors. Check errors and try again');
    expect($thrown->providerErrors)->toMatchArray(['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']]);

    $row = SmsDispatch::query()->latest('id')->first();
    expect($row->status)->toBe('failed');
    expect($row->response_code)->toBe('1003');
    expect($row->response_description)->toBe('Validation Errors. Check errors and try again');
    expect($row->metadata['provider_diagnostics']['errors'])->toMatchArray(['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']]);
    expect($row->metadata['provider_diagnostics']['parse_source'])->toBe('top-level');
    expect($row->metadata['provider_diagnostics']['provider'])->toBe('textsms');
});

it('falls back to an unparseable diagnostic stub when TextSMS returns no recognisable shape', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response('<html>503</html>', 503, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => $this->gateway->send('0712345678', new SmsMessage(body: 'x')))
        ->toThrow(SmsProviderException::class);

    $row = SmsDispatch::query()->latest('id')->first();
    expect($row->status)->toBe('failed');
    expect($row->metadata['provider_diagnostics']['parse_source'])->toBe('unparseable');
    expect($row->metadata['provider_diagnostics']['http_status'])->toBe(503);
    expect($row->metadata['provider_diagnostics']['raw_body_truncated'])->toContain('503');
});

it('marks every bulk row failed when TextSMS rejects the whole batch with a top-level validation error', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendbulk/' => Http::response([
            'response-code' => 1003,
            'response-description' => 'Validation Errors. Check errors and try again',
            'errors' => ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']],
        ], 422),
    ]);

    $thrown = null;
    try {
        $this->gateway->sendBulk([
            ['recipient' => '0712345678', 'message' => new SmsMessage(body: 'a', clientReference: 'A')],
            ['recipient' => '0712999999', 'message' => new SmsMessage(body: 'b', clientReference: 'B')],
        ]);
    } catch (SmsProviderException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->providerCode)->toBe('1003');
    expect($thrown->providerErrors)->not->toBeNull();

    foreach (['A', 'B'] as $cid) {
        $row = SmsDispatch::query()->where('client_message_id', $cid)->first();
        expect($row->status)->toBe('failed');
        expect($row->response_code)->toBe('1003');
        expect($row->metadata['provider_diagnostics']['errors'])->not->toBeNull();
        expect($row->metadata['provider_diagnostics']['parse_source'])->toBe('top-level');
    }
});

it('persists http_status and raw_body_truncated on bulk rows when TextSMS returns an unparseable response', function (): void {
    Http::fake([
        'sms.textsms.co.ke/api/services/sendbulk/' => Http::response(
            '<html><body>502 Bad Gateway from upstream</body></html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    expect(fn () => $this->gateway->sendBulk([
        ['recipient' => '0712345678', 'message' => new SmsMessage(body: 'a', clientReference: 'A')],
        ['recipient' => '0712999999', 'message' => new SmsMessage(body: 'b', clientReference: 'B')],
    ]))->toThrow(SmsProviderException::class);

    foreach (['A', 'B'] as $cid) {
        $row = SmsDispatch::query()->where('client_message_id', $cid)->first();
        expect($row->status)->toBe('failed');
        expect($row->metadata['provider_diagnostics']['parse_source'])->toBe('unparseable');
        expect($row->metadata['provider_diagnostics']['http_status'])->toBe(502);
        expect($row->metadata['provider_diagnostics']['raw_body_truncated'])->toContain('502 Bad Gateway');
        expect($row->metadata['provider_diagnostics']['provider'])->toBe('textsms');
    }
});

it('fires SmsDispatchAccepted on a successful send', function (): void {
    Event::fake([SmsDispatchAccepted::class]);

    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'responses' => [[
                'respose-code' => 200,
                'response-description' => 'Success',
                'messageid' => 12345,
                'networkid' => '1',
            ]],
        ], 200),
    ]);

    $this->gateway->send('0712345678', new SmsMessage(body: 'event test'));

    Event::assertDispatched(
        SmsDispatchAccepted::class,
        fn (SmsDispatchAccepted $event): bool => $event->dispatch->status === 'sent'
            && $event->dispatch->provider_message_id === '12345',
    );
});

it('fires SmsDispatchFailed with structured providerErrors on validation rejection', function (): void {
    Event::fake([SmsDispatchFailed::class]);

    Http::fake([
        'sms.textsms.co.ke/api/services/sendsms/' => Http::response([
            'response-code' => 1003,
            'response-description' => 'Validation Errors. Check errors and try again',
            'errors' => ['shortcode' => ['Shortcode' => 'Sender ID is inactive or unassigned']],
        ], 422),
    ]);

    try {
        $this->gateway->send('0712345678', new SmsMessage(body: 'x', senderId: 'BAD'));
    } catch (SmsProviderException) {
        // expected
    }

    Event::assertDispatched(
        SmsDispatchFailed::class,
        fn (SmsDispatchFailed $event): bool => $event->providerCode === '1003'
            && $event->providerErrors !== null
            && $event->parseSource === 'top-level',
    );
});
