<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing\FakeSmsGateway;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Exceptions::fake();
});

/** @return array{recipient: string, message: SmsMessage} */
function fakeFidelityItem(?string $reference = null): array
{
    return ['recipient' => '0700000000', 'message' => new SmsMessage('  Synthetic fake body  ', clientReference: $reference)];
}

it('requires a facade application when installing the fake', function (): void {
    $application = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);
    try {
        expect(fn () => FakeSmsGateway::install())->toThrow(RuntimeException::class);
    } finally {
        Facade::setFacadeApplication($application);
        Container::setInstance($application);
    }
});

it('installs both contracts without eagerly resolving a notification manager', function (): void {
    $application = Facade::getFacadeApplication();
    $fresh = new Application;
    Facade::setFacadeApplication($fresh);
    try {
        $fresh->resolving(ChannelManager::class, fn () => throw new RuntimeException('manager resolved eagerly'));
        $fake = FakeSmsGateway::install();
        expect($fresh->make(SmsSender::class))->toBe($fake)
            ->and($fresh->make(SmsDeliveryReportProcessor::class))->toBe($fake)
            ->and($fresh->resolved(ChannelManager::class))->toBeFalse();
    } finally {
        Facade::setFacadeApplication($application);
        Container::setInstance($application);
    }
});

it('intercepts a real manager channel that was cached before installation', function (): void {
    $manager = app(ChannelManager::class);
    $original = $manager->driver('sms');
    $fake = FakeSmsGateway::install();
    $notification = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['sms'];
        }

        public function toSms(object $notifiable): SmsMessage
        {
            return new SmsMessage('Synthetic notification');
        }
    };
    $manager->send((new AnonymousNotifiable)->route('sms', '0700000000'), $notification);

    expect($manager->driver('sms'))->not->toBe($original);
    $fake->assertSentCount(1);
    $fake->assertSentTo('254700000000');
    Http::assertNothingSent();
});

it('uses the bound normalizer and trims the persisted body', function (): void {
    app()->bind(PhoneNumberNormalizer::class, fn () => new class implements PhoneNumberNormalizer
    {
        public function normalize(?string $raw): ?string
        {
            return $raw === 'synthetic-alias' ? '254700000000' : null;
        }

        public function isValid(?string $candidate): bool
        {
            return $candidate === '254700000000';
        }
    });
    $fake = FakeSmsGateway::install();
    $row = $fake->send('synthetic-alias', new SmsMessage('  x  '));
    expect($row->fresh()->recipient)->toBe('254700000000')
        ->and($row->body)->toBe('x')
        ->and($row->client_message_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    $fake->assertSentTo('254700000000');
});

it('preflights every fake bulk input before any row or event', function (mixed $invalid): void {
    Event::fake([SmsDispatchAccepted::class, SmsDispatchFailed::class]);
    $fake = new FakeSmsGateway;
    expect(fn () => $fake->sendBulk([fakeFidelityItem('A'), $invalid]))->toThrow(SmsDispatchException::class, '1');
    expect(SmsDispatch::count())->toBe(0);
    $fake->assertNothingSent();
    Event::assertNotDispatched(SmsDispatchAccepted::class);
    Event::assertNotDispatched(SmsDispatchFailed::class);
})->with([
    'non-array' => [false],
    'missing message' => [['recipient' => '0700000000']],
    'wrong message' => [['recipient' => '0700000000', 'message' => 'x']],
    'numeric recipient' => [fn () => ['recipient' => 700000000, 'message' => new SmsMessage('x')]],
    'invalid phone' => [fn () => ['recipient' => 'invalid', 'message' => new SmsMessage('x')]],
    'blank body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage('  ')]],
    'invalid notifiable' => [fn () => fakeFidelityItem('B') + ['notifiable' => false]],
    'duplicate reference' => [fn () => fakeFidelityItem('A')],
    'blank reference' => [fn () => fakeFidelityItem('  ')],
    'long reference' => [fn () => fakeFidelityItem(str_repeat('R', 256))],
    'invalid UTF-8 body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage("bad\xB1")]],
    'invalid JSON metadata' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage('x', metadata: ['bad' => NAN])]],
]);

it('validates fake input before its configured provider failure', function (): void {
    $fake = (new FakeSmsGateway)->shouldThrow(new SmsProviderException('Synthetic refusal'));
    expect(fn () => $fake->send('invalid', new SmsMessage('x')))->toThrow(SmsDispatchException::class);
    expect(SmsDispatch::count())->toBe(0);
});

it('keeps empty fake batches inert and enforces the twenty-item boundary', function (): void {
    $fake = new FakeSmsGateway;
    $fake->assertNothingSent();
    expect($fake->sendBulk([]))->toHaveCount(0);
    expect(fn () => $fake->sendBulk(array_fill(0, 21, fakeFidelityItem())))->toThrow(SmsDispatchException::class);
    expect(SmsDispatch::count())->toBe(0);
    $rows = $fake->sendBulk(array_fill(0, 20, fakeFidelityItem()));
    expect($rows)->toHaveCount(20)->and($rows->pluck('client_message_id')->unique())->toHaveCount(20);
    $fake->assertSentCount(20);
});

it('propagates fake database failures without reporting a successful send', function (): void {
    Event::fake([SmsDispatchAccepted::class]);
    Schema::drop('sms_dispatches');
    $fake = new FakeSmsGateway;
    expect(fn () => $fake->send('0700000000', new SmsMessage('x')))->toThrow(QueryException::class);
    $fake->assertNothingSent();
    Event::assertNotDispatched(SmsDispatchAccepted::class);
});

it('rolls back a later fake save failure on its configured connection', function (bool $cancel): void {
    config()->set('database.connections.fake_secondary', config('database.connections.testing'));
    $definition = DB::connection('testing')->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='sms_dispatches'");
    DB::connection('fake_secondary')->statement($definition->sql);
    config()->set('textsms.database.connection', 'fake_secondary');
    Event::fake([SmsDispatchAccepted::class]);
    SmsDispatch::creating(function (SmsDispatch $row) use ($cancel): ?bool {
        if ($row->client_message_id !== 'B') {
            return null;
        }
        if ($cancel) {
            return false;
        }
        throw new RuntimeException('Synthetic later save failure');
    });
    $fake = new FakeSmsGateway;
    expect(fn () => $fake->sendBulk([fakeFidelityItem('A'), fakeFidelityItem('B')]))->toThrow(RuntimeException::class);
    expect(SmsDispatch::count())->toBe(0)->and(DB::connection('testing')->table('sms_dispatches')->count())->toBe(0);
    $fake->assertNothingSent();
    Event::assertNotDispatched(SmsDispatchAccepted::class);
})->with(['exception' => [false], 'cancelled save' => [true]]);

it('saves all fake bulk rows before publishing in input order and isolates listener failures', function (): void {
    $seen = [];
    Event::listen(SmsDispatchAccepted::class, function (SmsDispatchAccepted $event) use (&$seen): void {
        expect(SmsDispatch::count())->toBe(2);
        $seen[] = $event->dispatch->client_message_id;
        throw new RuntimeException('Synthetic accepted listener failure');
    });
    $fake = new FakeSmsGateway;
    $rows = $fake->sendBulk([7 => fakeFidelityItem('A'), 19 => fakeFidelityItem('B')]);
    expect($rows->keys()->all())->toBe([0, 1])->and($seen)->toBe(['A', 'B']);
    $fake->assertSentCount(2);
    Exceptions::assertReported(RuntimeException::class);
});

it('records sticky fake failures before events and preserves the configured exception', function (): void {
    $failure = new SmsProviderException('Synthetic refusal', '1004', 'No synthetic credits', ['synthetic' => 'failure']);
    $fake = (new FakeSmsGateway)->shouldThrow($failure);
    $seen = [];
    Event::listen(SmsDispatchFailed::class, function (SmsDispatchFailed $event) use (&$seen): void {
        expect(SmsDispatch::where('status', 'failed')->count())->toBeGreaterThanOrEqual(2);
        $seen[] = $event->dispatch->client_message_id;
        expect($event->providerCode)->toBe('1004');
        throw new RuntimeException('Synthetic failed listener');
    });
    foreach ([[fakeFidelityItem('A'), fakeFidelityItem('B')], [fakeFidelityItem('C')]] as $batch) {
        $caught = null;
        try {
            $fake->sendBulk($batch);
        } catch (Throwable $error) {
            $caught = $error;
        }
        expect($caught)->toBe($failure);
    }
    expect($seen)->toBe(['A', 'B', 'C'])
        ->and(SmsDispatch::where('status', 'failed')->count())->toBe(3)
        ->and(SmsDispatch::whereNotNull('sent_at')->count())->toBe(0)
        ->and(SmsDispatch::whereNotNull('failed_at')->count())->toBe(3);
    $fake->assertNothingSent();
});

it('contains a failing exception reporter while returning a fake success', function (): void {
    $handler = Exceptions::getFacadeRoot()->handler();
    Exceptions::swap($handler);
    $handler->reportable(fn (Throwable $error) => throw new LogicException('Synthetic reporter failure'));
    Event::listen(SmsDispatchAccepted::class, fn () => throw new RuntimeException('Synthetic listener failure'));
    expect((new FakeSmsGateway)->send('0700000000', new SmsMessage('x'))->status)->toBe('sent');
});

it('keeps fake callbacks passive and exposes a synthetic balance', function (): void {
    $fake = new FakeSmsGateway;
    $row = $fake->send('0700000000', new SmsMessage('x'));
    $before = $row->fresh()->getAttributes();
    Event::fake([SmsDeliveryReported::class]);
    expect($fake->recordDeliveryReport(['messageid' => $row->provider_message_id, 'status' => 'Delivered']))->toBeNull()
        ->and($row->fresh()->getAttributes())->toBe($before)
        ->and($fake->balance()['provider'])->toBe('fake');
    Event::assertNotDispatched(SmsDeliveryReported::class);
    Http::assertNothingSent();
});
