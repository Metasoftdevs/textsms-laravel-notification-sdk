<?php

declare(strict_types=1);

use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;

/*
|--------------------------------------------------------------------------
| TextSMS Laravel Notification SDK — Package Configuration
|--------------------------------------------------------------------------
|
| This file is published into the consuming application's `config/`
| directory via:
|
|   php artisan vendor:publish --tag=textsms-config
|
| Every binding can be overridden by environment variable. Sensible
| defaults are baked in so a fresh install only needs the five credential
| keys (`TEXTSMS_API_KEY`, `TEXTSMS_PARTNER_ID`, `TEXTSMS_SENDER_ID`,
| `TEXTSMS_DLR_SECRET`, and optionally `TEXTSMS_WEBHOOK_PREFIX`).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Default phone-number normaliser binding
    |--------------------------------------------------------------------------
    |
    | The SDK ships `KenyanE164Normalizer` and resolves it through this
    | binding. Consumers serving additional regions should bind a
    | `ChainedNormalizer` from their AppServiceProvider with an ordered
    | list of single-region normalisers — see docs/extending-normalizers.md.
    |
    */
    'phone_normalizer' => KenyanE164Normalizer::class,

    /*
    |--------------------------------------------------------------------------
    | Default outbound SMS sender
    |--------------------------------------------------------------------------
    |
    | The fully-qualified class name of the gateway bound to
    | `SmsSender::class` and `SmsDeliveryReportProcessor::class`. Swap to
    | `LogOnlySmsGateway` for local dev or CI where you want the full
    | notification pipeline to run without spending real credits.
    |
    */
    'default_sender' => TextSmsGateway::class,

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider credential blocks. Today only `textsms` ships; future
    | providers (Africa's Talking, Twilio, etc.) are documented in
    | docs/extending-providers.md and can be added under this key.
    |
    */
    'providers' => [
        'textsms' => [
            'base_url' => env('TEXTSMS_BASE_URL', 'https://sms.textsms.co.ke'),
            'api_key' => env('TEXTSMS_API_KEY'),
            'partner_id' => env('TEXTSMS_PARTNER_ID'),
            'default_sender_id' => env('TEXTSMS_SENDER_ID'),
            'timeout' => (int) env('TEXTSMS_TIMEOUT', 15),
            'dlr_secret' => env('TEXTSMS_DLR_SECRET'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The `sms_dispatches` table records submission attempts and informational
    | delivery-report payloads. Callbacks do not change submission state.
    | Override the connection or table name here to repurpose the model
    | against an alternative schema.
    |
    */
    'database' => [
        'connection' => env('TEXTSMS_DB_CONNECTION'),
        'table' => env('TEXTSMS_DB_TABLE', 'sms_dispatches'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    |
    | The DLR endpoint registered automatically by the service provider when
    | `enabled` is true. The SDK accepts POST callbacks; the account's live
    | provider method/schema/registration/retry contract needs confirmation.
    | If it matches this endpoint, register a URL such as:
    |
    |   https://<host>/<url_prefix>/textsms/dlr?token=<TEXTSMS_DLR_SECRET>
    |
    */
    'webhook' => [
        'enabled' => (bool) env('TEXTSMS_WEBHOOK_ENABLED', true),
        'url_prefix' => env('TEXTSMS_WEBHOOK_PREFIX', 'api/v1/sms'),
        'rate_limit_per_minute' => (int) env('TEXTSMS_WEBHOOK_RATE_LIMIT', 600),
        // Optional additional middleware; authentication and the named limiter
        // are already applied by the package route.
        'middleware' => [],
    ],

];
