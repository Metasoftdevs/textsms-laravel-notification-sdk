<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests;

use Illuminate\Foundation\Application;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the package suite.
 *
 * Boots a minimal Laravel application via Orchestra Testbench, registers
 * the package's service provider, points the in-memory SQLite database
 * at the `sms_dispatches` table, and loads package migrations through the
 * supported `defineDatabaseMigrations()` hook so it co-operates with the
 * `RefreshDatabase` trait used in Feature tests.
 *
 * The suite loads the same discoverable PHP migration used by fresh package
 * installations. Native migration publishing respects host configuration.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TextSmsNotificationServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('textsms.providers.textsms', [
            'base_url' => 'https://sms.textsms.co.ke',
            'api_key' => 'test-api-key',
            'partner_id' => '15682',
            'default_sender_id' => 'TestSender',
            'timeout' => 5,
            'dlr_secret' => 'test-dlr-secret',
        ]);

        $app['config']->set('textsms.webhook.enabled', true);
        $app['config']->set('textsms.webhook.url_prefix', 'api/v1/sms');
        $app['config']->set('textsms.webhook.middleware', []);
        $app['config']->set('textsms.database.connection', null);
        $app['config']->set('textsms.database.table', 'sms_dispatches');
    }

    /**
     * Orchestra hook called during application bootstrapping to define the
     * discoverable package-side migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
