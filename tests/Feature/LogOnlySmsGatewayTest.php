<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDeliveryReported;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\LogOnlySmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Exceptions::fake();
    $this->logSender = new LogOnlySmsGateway(app(PhoneNumberNormalizer::class), app(LoggerInterface::class));
});

/** @return array{recipient: string, message: SmsMessage} */
function logFidelityItem(?string $reference = null): array
{
    return ['recipient' => '0700000000', 'message' => new SmsMessage('  Synthetic log body  ', clientReference: $reference)];
}

it('normalizes log-only sends and generates client references without HTTP', function (): void {
    $row = $this->logSender->send('0700000000', new SmsMessage('  x  ', senderId: 'SYNTHETIC'));
    expect($row->fresh()->recipient)->toBe('254700000000')->and($row->body)->toBe('x')
        ->and($row->sender_id)->toBe('SYNTHETIC')->and($row->status)->toBe('sent')
        ->and($row->client_message_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
    Http::assertNothingSent();
});

it('preflights all log-only items before inserting or publishing', function (mixed $invalid): void {
    Event::fake([SmsDispatchAccepted::class]);
    expect(fn () => $this->logSender->sendBulk([logFidelityItem('A'), $invalid]))->toThrow(SmsDispatchException::class, '1');
    expect(SmsDispatch::count())->toBe(0);
    Event::assertNotDispatched(SmsDispatchAccepted::class);
})->with([
    'non-array' => [false],
    'missing message' => [['recipient' => '0700000000']],
    'wrong recipient type' => [fn () => ['recipient' => false, 'message' => new SmsMessage('x')]],
    'invalid phone' => [fn () => ['recipient' => 'invalid', 'message' => new SmsMessage('x')]],
    'blank body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage(' ')]],
    'invalid notifiable' => [fn () => logFidelityItem('B') + ['notifiable' => false]],
    'duplicate reference' => [fn () => logFidelityItem('A')],
    'blank reference' => [fn () => logFidelityItem(' ')],
    'long reference' => [fn () => logFidelityItem(str_repeat('R', 256))],
    'invalid UTF-8 body' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage("bad\xB1")]],
    'invalid JSON metadata' => [fn () => ['recipient' => '0700000000', 'message' => new SmsMessage('x', metadata: ['bad' => NAN])]],
]);

it('enforces empty and twenty-message log-only batch boundaries', function (): void {
    expect($this->logSender->sendBulk([]))->toHaveCount(0);
    expect(fn () => $this->logSender->sendBulk(array_fill(0, 21, logFidelityItem())))->toThrow(SmsDispatchException::class);
    expect(SmsDispatch::count())->toBe(0);
    $rows = $this->logSender->sendBulk(array_fill(0, 20, logFidelityItem()));
    expect($rows)->toHaveCount(20)->and($rows->pluck('client_message_id')->unique())->toHaveCount(20);
});

it('rolls back log-only batches after a later cancelled or throwing save', function (bool $cancel): void {
    config()->set('database.connections.log_secondary', config('database.connections.testing'));
    $definition = DB::connection('testing')->selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='sms_dispatches'");
    DB::connection('log_secondary')->statement($definition->sql);
    config()->set('textsms.database.connection', 'log_secondary');
    Event::fake([SmsDispatchAccepted::class]);
    SmsDispatch::creating(function (SmsDispatch $row) use ($cancel): ?bool {
        if ($row->client_message_id !== 'B') {
            return null;
        }
        if ($cancel) {
            return false;
        }
        throw new RuntimeException('Synthetic log save failure');
    });
    expect(fn () => $this->logSender->sendBulk([logFidelityItem('A'), logFidelityItem('B')]))->toThrow(RuntimeException::class);
    expect(SmsDispatch::count())->toBe(0)->and(DB::connection('testing')->table('sms_dispatches')->count())->toBe(0);
    Event::assertNotDispatched(SmsDispatchAccepted::class);
})->with(['exception' => [false], 'cancelled save' => [true]]);

it('saves all log-only rows before input-order events and contains listeners and reporters', function (): void {
    $handler = Exceptions::getFacadeRoot()->handler();
    Exceptions::swap($handler);
    $handler->reportable(fn (Throwable $error) => throw new LogicException('Synthetic log reporter'));
    $seen = [];
    Event::listen(SmsDispatchAccepted::class, function (SmsDispatchAccepted $event) use (&$seen): void {
        expect(SmsDispatch::count())->toBe(2);
        $seen[] = $event->dispatch->client_message_id;
        throw new RuntimeException('Synthetic log listener');
    });
    $rows = $this->logSender->sendBulk([7 => logFidelityItem('A'), 19 => logFidelityItem('B')]);
    expect($rows->keys()->all())->toBe([0, 1])->and($seen)->toBe(['A', 'B']);
});

it('contains log-only logger failures after saving accepted outcomes', function (): void {
    $logger = new class extends AbstractLogger
    {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('Synthetic logger outage');
        }
    };
    $sender = new LogOnlySmsGateway(app(PhoneNumberNormalizer::class), $logger);
    Event::fake([SmsDispatchAccepted::class]);
    $rows = $sender->sendBulk([logFidelityItem('A'), logFidelityItem('B')]);
    expect($rows->pluck('status')->all())->toBe(['sent', 'sent'])->and(SmsDispatch::count())->toBe(2);
    Event::assertDispatchedTimes(SmsDispatchAccepted::class, 2);
});

it('keeps log-only callbacks passive without delivery events', function (): void {
    $row = $this->logSender->send('0700000000', new SmsMessage('x'));
    $before = $row->fresh()->getAttributes();
    Event::fake([SmsDeliveryReported::class]);
    expect($this->logSender->recordDeliveryReport(['messageid' => 'synthetic', 'status' => 'Delivered']))->toBeNull()
        ->and($row->fresh()->getAttributes())->toBe($before)
        ->and($this->logSender->balance()['provider'])->toBe('log-only');
    Event::assertNotDispatched(SmsDeliveryReported::class);
    Http::assertNothingSent();
});
