<?php

declare(strict_types=1);

/**
 * Routes published / auto-loaded by
 * {@see TextSmsNotificationServiceProvider}.
 *
 * Only loaded when `config('textsms.webhook.enabled')` is true. Consumers
 * who want to host the endpoint on a non-default URL prefix or behind
 * different middleware can either:
 *
 *  - tune `config('textsms.webhook.url_prefix')` and
 *    `config('textsms.webhook.middleware')`, or
 *  - publish this file via
 *    `php artisan vendor:publish --tag=textsms-routes`
 *    and customise the published copy.
 *
 * The endpoint registered here is:
 *
 *   POST {prefix}/textsms/dlr   route name: metasoftdevs.textsms.dlr
 *
 * which authenticates via `verify.textsms.callback` before consuming the
 * named `textsms-dlr` limiter registered by the service provider.
 */

use Illuminate\Support\Facades\Route;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers\TextSmsCallbackController;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;

Route::prefix((string) config('textsms.webhook.url_prefix', 'api/v1/sms'))
    ->name('metasoftdevs.textsms.')
    ->middleware((array) config('textsms.webhook.middleware', []))
    ->group(function (): void {
        Route::post('textsms/dlr', [TextSmsCallbackController::class, 'dlr'])
            ->middleware(['verify.textsms.callback', 'throttle:textsms-dlr'])
            ->name('dlr');
    });
