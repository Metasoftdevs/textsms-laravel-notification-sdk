<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use PHPUnit\Framework\Assert;

/**
 * In-memory test double for {@see SmsSender} (and
 * {@see SmsDeliveryReportProcessor}).
 *
 * Replaces the inline `FakeSmsGatewayForChannelTest` that lived inside
 * the QPIMS tests. Consumers install the fake into the container with one
 * line:
 *
 *   ```
 *   $fake = FakeSmsGateway::install();
 *   ```
 *
 * after which any notification dispatched through the `'sms'` channel
 * will hit the fake instead of the real {@see TextSmsGateway}.
 * Assertion helpers mirror the shape of Laravel's first-party fakes
 * (`Notification::fake()`, `Mail::fake()`, etc.):
 *
 *   ```
 *   $fake->assertSentCount(1);
 *   $fake->assertSentTo('254712345678');
 *   $fake->assertNothingSent();
 *   ```
 *
 * Persisting an {@see SmsDispatch} row on every send keeps the fake
 * behaviourally equivalent to a real gateway for downstream code that
 * relies on `afterSending()` receiving the row.
 *
 * Benchmark: derived from the inline fake at
 * `tests/Feature/Sms/SmsChannelTest.php` in QPIMS per Reflection §4.7.
 */
final class FakeSmsGateway implements SmsDeliveryReportProcessor, SmsSender
{
    /** @var list<array{recipient: string, message: SmsMessage, notifiable: ?Model, dispatch: SmsDispatch}> */
    public array $sent = [];

    public bool $shouldThrow = false;

    public ?SmsProviderException $providerException = null;

    /**
     * Bind a fresh fake into the container as the resolution target for
     * `SmsSender` and `SmsDeliveryReportProcessor`. Returns the fake so
     * tests can configure it and assert against it.
     */
    public static function install(): self
    {
        $fake = new self;

        $app = Facade::getFacadeApplication();

        if ($app !== null) {
            $app->instance(SmsSender::class, $fake);
            $app->instance(SmsDeliveryReportProcessor::class, $fake);
        }

        return $fake;
    }

    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        if ($this->shouldThrow) {
            throw $this->providerException ?? new SmsProviderException('FakeSmsGateway: configured to throw.');
        }

        $dispatch = new SmsDispatch([
            'provider' => 'fake',
            'recipient' => $recipient,
            'sender_id' => $message->senderId ?? 'FAKE',
            'body' => $message->body,
            'client_message_id' => $message->clientReference,
            'status' => 'sent',
            'attempt_count' => 1,
            'sent_at' => now(),
            'provider_message_id' => 'fake-'.Str::ulid()->toBase32(),
            'response_code' => '200',
            'response_description' => 'Success (fake)',
            'metadata' => $message->metadata,
        ]);

        if ($notifiable !== null) {
            $dispatch->notifiable_type = $notifiable::class;
            $dispatch->notifiable_id = $notifiable->getKey();
        }

        // Persist if the test environment has a connection, otherwise the
        // row stays in memory — both work for assertion purposes.
        try {
            $dispatch->save();
        } catch (\Throwable) {
            $dispatch->id = count($this->sent) + 1;
            $dispatch->exists = true;
        }

        $this->sent[] = [
            'recipient' => $recipient,
            'message' => $message,
            'notifiable' => $notifiable,
            'dispatch' => $dispatch,
        ];

        SmsDispatchAccepted::dispatch($dispatch);

        return $dispatch;
    }

    /**
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     */
    public function sendBulk(array $items): Collection
    {
        return collect($items)->map(
            fn (array $item): SmsDispatch => $this->send(
                $item['recipient'],
                $item['message'],
                $item['notifiable'] ?? null,
            ),
        );
    }

    public function recordDeliveryReport(array $payload): ?SmsDispatch
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(): array
    {
        return ['provider' => 'fake', 'credit' => 'unlimited'];
    }

    /**
     * Configure the fake to throw the provided exception on the next send.
     */
    public function shouldThrow(?SmsProviderException $exception = null): self
    {
        $this->shouldThrow = true;
        $this->providerException = $exception;

        return $this;
    }

    // ── Assertions ───────────────────────────────────────────────────────

    public function assertNothingSent(): void
    {
        Assert::assertSame(
            0,
            count($this->sent),
            'Expected no SMS to be sent through the fake gateway, but '.count($this->sent).' were.',
        );
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertSame(
            $expected,
            count($this->sent),
            'Expected '.$expected.' SMS to be sent through the fake gateway, but '.count($this->sent).' were.',
        );
    }

    public function assertSentTo(string $recipient): void
    {
        $hit = collect($this->sent)
            ->contains(fn (array $record): bool => $record['recipient'] === $recipient
                || ($record['dispatch']->recipient ?? null) === $recipient);

        Assert::assertTrue(
            $hit,
            'Expected an SMS to be sent to '.$recipient.', but the fake gateway recorded recipients: '
                .json_encode(array_map(fn ($r) => $r['recipient'], $this->sent), JSON_UNESCAPED_SLASHES),
        );
    }
}
