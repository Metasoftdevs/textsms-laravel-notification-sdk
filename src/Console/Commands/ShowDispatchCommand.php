<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands;

use Illuminate\Console\Command;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

/**
 * Pretty-print a single `sms_dispatches` row by its ULID.
 *
 * Useful in operations work when a customer reports an SMS issue and you
 * need a quick view of the row (and any structured
 * `metadata.provider_diagnostics`) without opening a query tool.
 *
 *   php artisan textsms:dispatch:show 01J8YK2C5X8WZJY3D5KQXT8MR2
 */
final class ShowDispatchCommand extends Command
{
    /** @var string */
    protected $signature = 'textsms:dispatch:show {ulid : The dispatch row\'s ULID (the public-safe identifier)}';

    /** @var string */
    protected $description = 'Pretty-print a single `sms_dispatches` row by its ULID.';

    public function handle(): int
    {
        $ulid = $this->argument('ulid');

        if (! is_string($ulid) || $ulid === '') {
            $this->error('Expected a single ULID argument.');

            return self::INVALID;
        }

        /** @var SmsDispatch|null $row */
        $row = SmsDispatch::query()->where('ulid', $ulid)->first();

        if ($row === null) {
            $this->error("No sms_dispatches row matched ulid={$ulid}.");

            return self::FAILURE;
        }

        $this->info("Dispatch #{$row->id} ({$row->ulid})");
        $this->table(['Field', 'Value'], [
            ['provider', (string) $row->provider],
            ['status', (string) $row->status],
            ['recipient', (string) $row->recipient],
            ['sender_id', (string) $row->sender_id],
            ['provider_message_id', (string) ($row->provider_message_id ?? '')],
            ['client_message_id', (string) ($row->client_message_id ?? '')],
            ['network_id', (string) ($row->network_id ?? '')],
            ['response_code', (string) ($row->response_code ?? '')],
            ['response_description', (string) ($row->response_description ?? '')],
            ['attempt_count', (string) $row->attempt_count],
            ['sent_at', (string) ($row->sent_at?->toDateTimeString() ?? '')],
            ['delivered_at', (string) ($row->delivered_at?->toDateTimeString() ?? '')],
            ['failed_at', (string) ($row->failed_at?->toDateTimeString() ?? '')],
            ['notifiable_type', (string) ($row->notifiable_type ?? '')],
            ['notifiable_id', (string) ($row->notifiable_id ?? '')],
            ['created_at', $row->created_at->toDateTimeString()],
        ]);

        $this->line('');
        $this->line('Body:');
        $this->line('  '.$row->body);

        if ($row->metadata !== null && $row->metadata !== []) {
            $this->line('');
            $this->line('Metadata:');
            $this->line('  '.json_encode($row->metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        if ($row->dlr_payload !== null && $row->dlr_payload !== []) {
            $this->line('');
            $this->line('DLR payload:');
            $this->line('  '.json_encode($row->dlr_payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
