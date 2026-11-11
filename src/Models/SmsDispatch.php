<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;

/**
 * Persistent log of every outbound SMS dispatched through an {@see SmsSender}.
 *
 * Each row tracks one delivery attempt against a provider. The polymorphic
 * `notifiable` relation links a row back to whatever model caused the
 * dispatch (Receipt, User, Order, etc.) so a single table serves every SMS
 * use case across the host application.
 *
 * The table name and connection are read from configuration so consumers
 * can repurpose the model against their own schema:
 *
 *   ```
 *   // config/textsms.php
 *   'database' => [
 *       'connection' => env('TEXTSMS_DB_CONNECTION', null),
 *       'table'      => env('TEXTSMS_DB_TABLE', 'sms_dispatches'),
 *   ],
 *   ```
 *
 * Submission status lifecycle:
 *
 *  - `pending`   row inserted before the provider HTTP call
 *  - `sent`      provider accepted the message (HTTP 200 + provider code `200`)
 *  - `failed`    unsuccessful attempt, including unknown provider acceptance
 *
 * Callbacks only replace `dlr_payload`, without changing state or timestamps.
 * Legacy `delivered`/`rejected` rows and delivery timestamp fields remain
 * readable; the default callback path no longer produces those transitions.
 *
 * Benchmark: ported from `app/Models/SmsDispatch.php` in QPIMS, with
 * connection/table reads moved into `getConnectionName()` and `getTable()`
 * so consumers can override via `config/textsms.php` without subclassing.
 *
 * @property int $id
 * @property string $ulid
 * @property string $provider
 * @property string|null $provider_message_id
 * @property string|null $client_message_id
 * @property string $recipient
 * @property string $sender_id
 * @property string $body
 * @property string $status
 * @property string|null $response_code
 * @property string|null $response_description
 * @property string|null $network_id
 * @property string|null $notifiable_type
 * @property int|string|null $notifiable_id
 * @property int $attempt_count
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property array<string, mixed>|null $dlr_payload
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SmsDispatch extends Model
{
    /**
     * Columns that are mass-assignable from arrays passed to `create()` or `fill()`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ulid',
        'provider',
        'provider_message_id',
        'client_message_id',
        'recipient',
        'sender_id',
        'body',
        'status',
        'response_code',
        'response_description',
        'network_id',
        'notifiable_type',
        'notifiable_id',
        'attempt_count',
        'sent_at',
        'delivered_at',
        'failed_at',
        'dlr_payload',
        'metadata',
    ];

    /**
     * Type-casts applied when reading/writing model attributes.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'dlr_payload' => 'array',
            'metadata' => 'array',
            'attempt_count' => 'integer',
        ];
    }

    /**
     * Resolve the database connection name from package configuration.
     * Returns null to indicate "use the application's default connection".
     */
    public function getConnectionName(): ?string
    {
        return config('textsms.database.connection');
    }

    /**
     * Resolve the table name from package configuration, defaulting to
     * `sms_dispatches` when nothing is configured.
     */
    public function getTable(): string
    {
        return (string) config('textsms.database.table', 'sms_dispatches');
    }

    /**
     * Polymorphic relation back to whatever model caused this dispatch.
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Route-model bindings should use the public-safe ULID, never the
     * auto-increment integer key.
     */
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Scope: rows still waiting for the provider to accept the message.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Legacy scope: `status=sent`, `delivered_at IS NULL`.
     * Passive callbacks do not set delivered_at. To find missing information,
     * query sent rows with `whereNull('dlr_payload')` instead.
     */
    public function scopeAwaitingDlr(Builder $query): Builder
    {
        return $query->where('status', 'sent')->whereNull('delivered_at');
    }

    /**
     * Scope: failed submissions and legacy rejected rows; not proof of non-delivery.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'rejected']);
    }

    /**
     * Lifecycle hook: generate a ULID for the row whenever one is not
     * supplied at creation time. The ULID is the external-facing identifier
     * (route-model binding key) and is unique across all dispatches.
     */
    protected static function booted(): void
    {
        static::creating(function (SmsDispatch $dispatch): void {
            if (empty($dispatch->ulid)) {
                $dispatch->ulid = (string) Str::ulid();
            }
        });
    }
}
