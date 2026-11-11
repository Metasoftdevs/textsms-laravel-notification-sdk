<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use JsonException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchFailed;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * Persisted, no-network test double for {@see SmsSender} (and
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
 * Input is validated before writes. Bulk rows save atomically on the
 * dispatch connection before any SDK event fires. Storage failures propagate;
 * immediate listener/reporter failures cannot change saved outcomes.
 * `shouldThrow()` is sticky until the public flag is reset or a fresh fake
 * is installed: valid attempts persist failed rows and fire failure events
 * before throwing the configured exception. Only accepted sends enter `sent`.
 * Callbacks are ignored without model mutations or delivery events.
 *
 * Benchmark: derived from the inline fake at
 * `tests/Feature/Sms/SmsChannelTest.php` in QPIMS per Reflection §4.7.
 */
final class FakeSmsGateway implements SmsDeliveryReportProcessor, SmsSender
{
    private const BULK_LIMIT = 20;

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
        $app = Facade::getFacadeApplication();
        if ($app === null) {
            throw new RuntimeException('FakeSmsGateway requires a Laravel application; extend the package or application TestCase.');
        }

        $fake = new self;
        $app->instance(SmsSender::class, $fake);
        $app->instance(SmsDeliveryReportProcessor::class, $fake);
        if ($app->resolved(ChannelManager::class)) {
            $app->make(ChannelManager::class)->forgetDrivers();
        }

        return $fake;
    }

    /** Record a simulated send, or raise the configured simulated provider exception. */
    public function send(string $recipient, SmsMessage $message, ?Model $notifiable = null): SmsDispatch
    {
        return $this->sendBulk([['recipient' => $recipient, 'message' => $message, 'notifiable' => $notifiable]])->firstOrFail();
    }

    /**
     * @param  array<int, array{recipient: string, message: SmsMessage, notifiable?: ?Model}>  $items
     */
    public function sendBulk(array $items): Collection
    {
        if (count($items) > self::BULK_LIMIT) {
            throw new SmsDispatchException('FakeSmsGateway accepts at most '.self::BULK_LIMIT.' messages per batch.');
        }
        if ($items === []) {
            return collect();
        }
        $prepared = $this->prepareItems($items);
        $failure = $this->shouldThrow
            ? ($this->providerException ?? new SmsProviderException('FakeSmsGateway: configured to throw.'))
            : null;

        $rows = (new SmsDispatch)->getConnection()->transaction(function () use ($prepared, $failure): array {
            $rows = [];
            foreach ($prepared as $item) {
                $message = $item['message'];
                $row = new SmsDispatch([
                    'provider' => 'fake',
                    'recipient' => $item['recipient'],
                    'sender_id' => $message->senderId ?? 'FAKE',
                    'body' => $item['body'],
                    'client_message_id' => $item['client_id'],
                    'status' => $failure === null ? 'sent' : 'failed',
                    'attempt_count' => 1,
                    'sent_at' => $failure === null ? now() : null,
                    'failed_at' => $failure === null ? null : now(),
                    'provider_message_id' => $failure === null ? 'fake-'.Str::ulid()->toBase32() : null,
                    'response_code' => $failure === null ? '200' : $failure->providerCode,
                    'response_description' => $failure === null ? 'Success (fake)' : mb_substr($failure->providerDescription ?? $failure->getMessage(), 0, 255, 'UTF-8'),
                    'metadata' => $message->metadata,
                ]);
                if ($item['notifiable'] !== null) {
                    $row->notifiable()->associate($item['notifiable']);
                }
                if (! $row->save()) {
                    throw new RuntimeException('A model event cancelled the fake dispatch save.');
                }
                $rows[] = $row;
            }

            return $rows;
        });

        foreach ($rows as $index => $row) {
            if ($failure === null) {
                $this->sent[] = [
                    'recipient' => $prepared[$index]['recipient'],
                    'message' => $prepared[$index]['message'],
                    'notifiable' => $prepared[$index]['notifiable'],
                    'dispatch' => $row,
                ];
            }
        }
        foreach ($rows as $row) {
            $this->publish($failure === null
                ? new SmsDispatchAccepted($row)
                : new SmsDispatchFailed($row, $failure->providerCode, $failure->providerDescription, $failure->providerErrors));
        }
        if ($failure !== null) {
            throw $failure;
        }

        return collect($rows);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{recipient: string, body: string, client_id: string, message: SmsMessage, notifiable: ?Model}>
     */
    private function prepareItems(array $items): array
    {
        $app = Facade::getFacadeApplication()
            ?? throw new RuntimeException('FakeSmsGateway requires a Laravel application for recipient normalization.');
        $normalizer = $app->make(PhoneNumberNormalizer::class);
        $prepared = [];
        $clientIds = [];
        foreach ($items as $index => $item) {
            try {
                if (! is_array($item) || ! isset($item['recipient']) || ! is_string($item['recipient'])) {
                    throw new SmsDispatchException('recipient must be a string on an array item.');
                }
                if (! isset($item['message']) || ! $item['message'] instanceof SmsMessage) {
                    throw new SmsDispatchException('message must be an SmsMessage.');
                }
                $notifiable = $item['notifiable'] ?? null;
                if ($notifiable !== null && ! $notifiable instanceof Model) {
                    throw new SmsDispatchException('notifiable must be a model or null.');
                }
                $message = $item['message'];
                $recipient = $normalizer->normalize($item['recipient'])
                    ?? throw new SmsDispatchException('Invalid recipient phone number.');
                $body = trim($message->body);
                if ($body === '') {
                    throw new SmsDispatchException('SMS body cannot be empty.');
                }
                $clientId = $message->clientReference ?? (string) Str::ulid()->toBase32();
                if (trim($clientId) === '' || mb_strlen($clientId, 'UTF-8') > 255 || in_array($clientId, $clientIds, true)) {
                    throw new SmsDispatchException('client reference must be non-blank, at most 255 characters and unique in the batch.');
                }
                json_encode([$recipient, $body, $message->senderId, $clientId, $message->metadata], JSON_THROW_ON_ERROR);
                $clientIds[] = $clientId;
                $prepared[] = ['recipient' => $recipient, 'body' => $body, 'client_id' => $clientId, 'message' => $message, 'notifiable' => $notifiable];
            } catch (SmsDispatchException|JsonException $error) {
                throw new SmsDispatchException("Bulk item {$index}: ".$error->getMessage(), previous: $error);
            }
        }

        return $prepared;
    }

    /** Contain immediate listener failures, including failures of exception reporting. */
    private function publish(object $event): void
    {
        rescue(fn () => event($event), fn (Throwable $error) => rescue(fn () => report($error), report: false), report: false);
    }

    /** Ignore sanitized inbound payloads; no upstream provider is contacted. */
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
     * Fail every valid send until `shouldThrow` is reset or a fresh fake is installed.
     */
    public function shouldThrow(?SmsProviderException $exception = null): self
    {
        $this->shouldThrow = true;
        $this->providerException = $exception;

        return $this;
    }

    // ── Assertions ───────────────────────────────────────────────────────

    /** Assert no send was recorded by this fake instance. */
    public function assertNothingSent(): void
    {
        Assert::assertSame(
            0,
            count($this->sent),
            'Expected no SMS to be sent through the fake gateway, but '.count($this->sent).' were.',
        );
    }

    /** Assert the number of sends recorded by this fake instance. */
    public function assertSentCount(int $expected): void
    {
        Assert::assertSame(
            $expected,
            count($this->sent),
            'Expected '.$expected.' SMS to be sent through the fake gateway, but '.count($this->sent).' were.',
        );
    }

    /** Assert a recorded send matches the supplied recipient. */
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
