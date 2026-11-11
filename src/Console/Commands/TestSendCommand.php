<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands;

use Illuminate\Console\Command;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsDispatchException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;

/**
 * Send a one-off test SMS through the configured sender.
 *
 * Used during initial credential provisioning to confirm the API key,
 * partner ID, and sender ID are accepted by the provider before any real
 * traffic flows.
 *
 *   php artisan textsms:test 0712345678
 *   php artisan textsms:test 0712345678 --body="Custom message"
 *   php artisan textsms:test 0712345678 --sender=CustomID
 *
 * The persisted dispatch row is echoed at the end so operators can copy
 * its `provider_message_id` for follow-up DLR verification.
 */
final class TestSendCommand extends Command
{
    /** @var string */
    protected $signature = 'textsms:test
                            {phone : Recipient phone number (any shape accepted by the configured normaliser)}
                            {--body= : Custom message body; defaults to a timestamped test marker}
                            {--sender= : Per-message sender override (defaults to the configured default)}';

    /** @var string */
    protected $description = 'Send a one-off test SMS through the configured sender.';

    /** Send the requested test message and return an Artisan exit status. */
    public function handle(SmsSender $sender): int
    {
        $phoneArg = $this->argument('phone');
        if (! is_string($phoneArg) || $phoneArg === '') {
            $this->error('Expected a single phone argument.');

            return self::INVALID;
        }
        $phone = $phoneArg;

        $bodyOption = $this->option('body');
        $body = is_string($bodyOption) && $bodyOption !== ''
            ? $bodyOption
            : 'TextSMS SDK test at '.now()->utc()->toDateTimeString().' UTC';

        $senderId = $this->option('sender');

        try {
            $dispatch = $sender->send(
                recipient: $phone,
                message: new SmsMessage(
                    body: $body,
                    senderId: is_string($senderId) ? $senderId : null,
                ),
            );
        } catch (SmsDispatchException $e) {
            $this->error('Bad input: '.$e->getMessage());

            return self::INVALID;
        } catch (SmsProviderException $e) {
            $this->error('SMS dispatch failed: '.$e->getMessage());
            if ($e->providerCode !== null) {
                $this->line('  provider_code        = '.$e->providerCode);
            }
            if ($e->providerDescription !== null) {
                $this->line('  provider_description = '.$e->providerDescription);
            }
            if ($e->providerErrors !== null) {
                $this->line('  provider_errors      = '.json_encode($e->providerErrors, JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }

        $this->info('SMS accepted by provider.');
        $this->table(
            ['Field', 'Value'],
            [
                ['ulid', (string) $dispatch->ulid],
                ['recipient', (string) $dispatch->recipient],
                ['sender_id', (string) $dispatch->sender_id],
                ['provider', (string) $dispatch->provider],
                ['provider_message_id', (string) ($dispatch->provider_message_id ?? '')],
                ['network_id', (string) ($dispatch->network_id ?? '')],
                ['response_code', (string) ($dispatch->response_code ?? '')],
                ['sent_at', (string) ($dispatch->sent_at?->toDateTimeString() ?? '')],
            ],
        );

        return self::SUCCESS;
    }
}
