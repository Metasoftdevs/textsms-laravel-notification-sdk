<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\TestCase;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;

final class MigrationPublishingTest extends TestCase
{
    use RefreshDatabase;

    private string $publishRoot;

    private array $previousMigrationPaths;

    protected function setUp(): void
    {
        // Laravel keeps this registration static across Testbench applications.
        $property = new \ReflectionProperty(ServiceProvider::class, 'publishableMigrationPaths');
        $this->previousMigrationPaths = $property->getValue();
        $property->setValue(null, []);
        parent::setUp();
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->publishRoot = sys_get_temp_dir().'/textsms-migrations-'.Str::uuid();
        $app->useDatabasePath($this->publishRoot);
        $app['config']->set('database.migrations.update_date_on_publish', false);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->publishRoot)) {
                File::deleteDirectory($this->publishRoot);
            }
        } finally {
            try {
                parent::tearDown();
            } finally {
                (new \ReflectionProperty(ServiceProvider::class, 'publishableMigrationPaths'))->setValue(null, $this->previousMigrationPaths);
            }
        }
    }

    public function test_a_fresh_schema_accepts_submission_statuses_and_defaults_to_one_attempt(): void
    {
        $row = SmsDispatch::create([
            'provider' => 'synthetic', 'recipient' => '254700000000', 'sender_id' => 'TEST',
            'body' => 'Synthetic schema', 'status' => 'custom',
        ])->fresh();
        self::assertSame('custom', $row->status);
        self::assertSame(1, $row->attempt_count);
    }

    public function test_the_native_publish_group_uses_discoverable_php_migrations(): void
    {
        $paths = ServiceProvider::pathsToPublish(TextSmsNotificationServiceProvider::class, 'textsms-migrations');
        self::assertCount(1, $paths);
        $source = array_key_first($paths);
        self::assertDirectoryExists($source);
        self::assertFileExists($source.'/2026_06_08_000000_create_sms_dispatches_table.php');
        self::assertSame(str_replace('\\', '/', $this->publishRoot.'/migrations'), str_replace('\\', '/', $paths[$source]));
    }

    public function test_publishing_respects_the_applications_disabled_date_rewrite_setting(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'textsms-migrations', '--no-interaction' => true])->assertSuccessful();
        self::assertFileExists($this->publishRoot.'/migrations/2026_06_08_000000_create_sms_dispatches_table.php');
        self::assertCount(1, File::files($this->publishRoot.'/migrations'));
    }
}
