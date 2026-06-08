<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands;

use Illuminate\Console\Command;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;

/**
 * Print the configured SMS sender's account balance.
 *
 * Useful as a quick health check and as a one-liner in CI / cron jobs that
 * watch for credit depletion before sends start failing with provider
 * code `1004`.
 *
 *   php artisan textsms:balance
 */
final class BalanceCommand extends Command
{
    /** @var string */
    protected $signature = 'textsms:balance';

    /** @var string */
    protected $description = 'Print the configured SMS sender\'s account balance.';

    public function handle(SmsSender $sender): int
    {
        try {
            $balance = $sender->balance();
        } catch (SmsProviderException $e) {
            $this->error('Balance lookup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Provider balance:');
        $this->table(
            ['Field', 'Value'],
            collect($balance)
                ->map(fn (mixed $value, string $key): array => [
                    $key,
                    is_scalar($value) || $value === null
                        ? (string) ($value ?? '')
                        : json_encode($value, JSON_UNESCAPED_SLASHES),
                ])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }
}
