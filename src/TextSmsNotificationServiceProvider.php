<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\BalanceCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\ShowDispatchCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\TestSendCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware\VerifyTextSmsCallback;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Notifications\Channels\SmsChannel;
use Psr\Log\LoggerInterface;

/**
 * Top-level Laravel service provider for the SDK.
 *
 * Responsibilities split between `register()` and `boot()` follow the
 * Laravel convention:
 *
 *  - `register()`: merge config defaults and bind contracts into the
 *                  container so other code can resolve them. Must NOT
 *                  touch any other service (other providers may not be
 *                  registered yet).
 *
 *  - `boot()`:     register the `'sms'` notification channel alias,
 *                  the `verify.textsms.callback` middleware alias, the
 *                  artisan commands (CLI runs only), the publishable
 *                  config / migration / routes, and load
 *                  `routes/webhooks.php` when the webhook is enabled.
 *
 * The package is auto-discovered via the `extra.laravel.providers` block
 * in `composer.json`.
 */
class TextSmsNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/textsms.php', 'textsms');

        $this->app->bind(PhoneNumberNormalizer::class, function (Container $app): PhoneNumberNormalizer {
            /** @var class-string<PhoneNumberNormalizer> $class */
            $class = (string) config('textsms.phone_normalizer');

            return $app->make($class);
        });

        // Bind the configured sender as a singleton to both contracts so
        // a single instance services outbound (SmsSender) and inbound
        // (SmsDeliveryReportProcessor) within a request lifecycle.
        $this->app->singleton(SmsSender::class, function (Container $app): SmsSender {
            return $this->makeConfiguredSender($app);
        });

        $this->app->singleton(SmsDeliveryReportProcessor::class, function (Container $app): SmsDeliveryReportProcessor {
            $sender = $app->make(SmsSender::class);

            if (! $sender instanceof SmsDeliveryReportProcessor) {
                throw new \RuntimeException(
                    'The configured `textsms.default_sender` ('.$sender::class.') must also implement '
                    .SmsDeliveryReportProcessor::class.' to handle delivery-report webhooks. '
                    .'Either swap to a gateway that supports both surfaces, or bind '
                    .SmsDeliveryReportProcessor::class.' separately in your AppServiceProvider.'
                );
            }

            return $sender;
        });
    }

    public function boot(): void
    {
        $this->registerChannel();
        $this->registerMiddlewareAlias();
        $this->registerPublishables();

        if ((bool) config('textsms.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                BalanceCommand::class,
                TestSendCommand::class,
                ShowDispatchCommand::class,
            ]);
        }
    }

    /**
     * Instantiate the configured sender, resolving dependencies (phone
     * normaliser, PSR-3 logger, credentials) from the container/config.
     */
    private function makeConfiguredSender(Container $app): SmsSender
    {
        /** @var class-string<SmsSender> $senderClass */
        $senderClass = (string) config('textsms.default_sender');

        if ($senderClass === TextSmsGateway::class) {
            $provider = (array) config('textsms.providers.textsms', []);

            return new TextSmsGateway(
                baseUrl: rtrim((string) ($provider['base_url'] ?? 'https://sms.textsms.co.ke'), '/'),
                apiKey: (string) ($provider['api_key'] ?? ''),
                partnerId: (string) ($provider['partner_id'] ?? ''),
                defaultSenderId: (string) ($provider['default_sender_id'] ?? ''),
                normalizer: $app->make(PhoneNumberNormalizer::class),
                logger: $app->make(LoggerInterface::class),
                timeoutSeconds: (int) ($provider['timeout'] ?? 15),
            );
        }

        // Custom sender bound by class name — let the container construct
        // it (auto-wires PhoneNumberNormalizer, LoggerInterface, etc.).
        return $app->make($senderClass);
    }

    /**
     * Register the `'sms'` driver against Laravel's notification ChannelManager.
     * Notifications can then return the friendly string `'sms'` from `via()`.
     */
    private function registerChannel(): void
    {
        /** @var ChannelManager $manager */
        $manager = $this->app->make(ChannelManager::class);

        $manager->extend('sms', function (Application $app): SmsChannel {
            return new SmsChannel(
                sender: $app->make(SmsSender::class),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }

    /**
     * Register the `verify.textsms.callback` route-middleware alias so the
     * package's routes file (and consumer-published copies) can reference
     * the middleware by its short name.
     */
    private function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('verify.textsms.callback', VerifyTextSmsCallback::class);

        // Also surface through the HTTP kernel for consumers using the
        // newer `bootstrap/app.php` alias block (Laravel 11+/12).
        if ($this->app->bound(HttpKernel::class)) {
            // No-op for now: Laravel auto-resolves route middleware aliases
            // registered through Router. Kept here as an extension point.
        }
    }

    /**
     * Register the publishable artefacts. Consumers run
     * `vendor:publish --tag=...` to copy each into their project.
     */
    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/textsms.php' => $this->app->configPath('textsms.php'),
        ], 'textsms-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub' => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_sms_dispatches_table.php'),
        ], 'textsms-migrations');

        $this->publishes([
            __DIR__.'/../routes/webhooks.php' => $this->app->basePath('routes/textsms-webhooks.php'),
        ], 'textsms-routes');
    }
}
