<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([SmsDeliveryReported::class, SmsDispatchAccepted::class, SmsDispatchFailed::class]);
});

/** @param array<string, mixed> $attributes */
function passiveReportRow(array $attributes = []): SmsDispatch
{
    return SmsDispatch::create($attributes + [
        'provider' => 'textsms', 'provider_message_id' => 'passive-report-id',
        'recipient' => '254700000000', 'sender_id' => 'SYNTHETIC', 'body' => 'Synthetic passive report',
        'status' => 'sent', 'sent_at' => '2026-11-09 08:00:00',
        'attempt_count' => 1, 'updated_at' => '2026-11-08 08:00:00',
    ]);
}

it('records only the latest informational payload without submission or lifecycle changes', function (string $status): void {
    $row = passiveReportRow(['delivered_at' => '2026-11-08 09:00:00', 'failed_at' => '2026-11-08 10:00:00']);
    $before = $row->getAttributes();

    $this->postJson('/api/v1/sms/textsms/dlr?token=test-dlr-secret', ['messageid' => 'passive-report-id', 'status' => $status])->assertOk();

    $stored = $row->fresh()->getAttributes();
    foreach (['status', 'sent_at', 'delivered_at', 'failed_at', 'updated_at', 'provider_message_id', 'body', 'attempt_count'] as $field) {
        expect($stored[$field])->toBe($before[$field]);
    }
    expect($row->fresh()->dlr_payload)->toBe(['messageid' => 'passive-report-id', 'status' => $status]);
    Event::assertNotDispatched(SmsDeliveryReported::class);
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Event::assertNotDispatched(SmsDispatchFailed::class);
    Http::assertNothingSent();
})->with(['Delivered', 'Failed', 'buffered']);

it('does not execute model retrieval or write observers while recording a callback', function (): void {
    $row = passiveReportRow();
    foreach (['retrieved', 'saving', 'updating', 'updated', 'saved'] as $event) {
        SmsDispatch::{$event}(fn () => throw new RuntimeException('Synthetic observer must not run'));
    }

    $this->postJson('/api/v1/sms/textsms/dlr?token=test-dlr-secret', ['messageid' => 'passive-report-id', 'status' => 'Delivered'])->assertOk();

    $stored = DB::table('sms_dispatches')->where('id', $row->id)->first();
    expect($stored->status)->toBe('sent')
        ->and(json_decode($stored->dlr_payload, true))->toBe(['messageid' => 'passive-report-id', 'status' => 'Delivered']);
});

it('lets repeated and reordered callbacks replace information without deciding delivery precedence', function (): void {
    $row = passiveReportRow();
    foreach (['Delivered', 'Failed', 'Delivered', 'buffered'] as $status) {
        $this->postJson('/api/v1/sms/textsms/dlr?token=test-dlr-secret', ['messageid' => 'passive-report-id', 'status' => $status])->assertOk();
    }
    expect($row->fresh()->status)->toBe('sent')
        ->and($row->fresh()->delivered_at)->toBeNull()
        ->and($row->fresh()->failed_at)->toBeNull()
        ->and($row->fresh()->dlr_payload['status'])->toBe('buffered');
    Event::assertNotDispatched(SmsDeliveryReported::class);
});

it('does not acknowledge a delivery payload when the database write fails', function (): void {
    passiveReportRow();
    DB::unprepared("CREATE TRIGGER reject_passive_report BEFORE UPDATE ON sms_dispatches BEGIN SELECT RAISE(FAIL, 'Synthetic storage failure'); END");
    $this->postJson('/api/v1/sms/textsms/dlr?token=test-dlr-secret', ['messageid' => 'passive-report-id', 'status' => 'Delivered'])->assertStatus(500);
    expect(DB::table('sms_dispatches')->value('dlr_payload'))->toBeNull();
    Event::assertNotDispatched(SmsDeliveryReported::class);
});

it('uses the configured model connection and records a sanitized direct processor payload', function (): void {
    config()->set('database.connections.passive_alternate', config('database.connections.testing'));
    config()->set('textsms.database.connection', 'passive_alternate');
    $migration = require __DIR__.'/../../database/migrations/2026_06_08_000000_create_sms_dispatches_table.php';
    $migration->up();
    $row = passiveReportRow();

    $result = app(SmsDeliveryReportProcessor::class)->recordDeliveryReport(['messageID' => 'passive-report-id', 'dlrstatus' => 'Delivered']);
    expect($result?->getKey())->toBe($row->getKey())
        ->and($result?->status)->toBe('sent')
        ->and($result?->dlr_payload)->toBe(['messageID' => 'passive-report-id', 'dlrstatus' => 'Delivered'])
        ->and(DB::connection('testing')->table('sms_dispatches')->count())->toBe(0);
    Event::assertNotDispatched(SmsDeliveryReported::class);
});
