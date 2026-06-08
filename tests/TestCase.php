<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Application;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the package suite.
 *
 * Boots a minimal Laravel application via Orchestra Testbench, registers
 * the package's service provider, points the in-memory SQLite database
 * at the `sms_dispatches` table, and runs the migration stub through the
 * supported `defineDatabaseMigrations()` hook so it co-operates with the
 * `RefreshDatabase` trait used in Feature tests.
 *
 * The package ships its migration as a `.stub` (so the `vendor:publish`
 * tag can rewrite the timestamp on copy). Since Orchestra's
 * `loadMigrationsFrom()` only discovers `.php` extensions, we manually
 * `require` the stub here and call `up()` on the returned migration.
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
     * package-side migrations. The stub returns an anonymous Migration
     * class which we instantiate and call `up()` on directly.
     */
    protected function defineDatabaseMigrations(): void
    {
        /** @var Migration $migration */
        $migration = require __DIR__.'/../database/migrations/2026_06_08_000000_create_sms_dispatches_table.php.stub';

        $migration->up();
    }
}
