<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\TestCase;
use Mockery;

final class CustomWebhookBootTest extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('textsms.webhook.url_prefix', 'synthetic/reports');
    }

    public function test_a_prefix_configured_before_boot_registers_and_processes_the_authenticated_route(): void
    {
        Http::preventStrayRequests();
        $route = $this->app->make(Router::class)->getRoutes()->getByName('metasoftdevs.textsms.dlr');
        self::assertNotNull($route);
        self::assertSame('synthetic/reports/textsms/dlr', $route->uri());
        self::assertSame(['POST'], $route->methods());
        $processor = Mockery::mock(SmsDeliveryReportProcessor::class);
        $processor->shouldReceive('recordDeliveryReport')->once()
            ->with(['messageid' => 'synthetic-report', 'status' => 'Delivered'])->andReturnNull();
        $this->app->instance(SmsDeliveryReportProcessor::class, $processor);

        $this->postJson('/synthetic/reports/textsms/dlr?token=test-dlr-secret', [
            'messageid' => 'synthetic-report', 'status' => 'Delivered',
        ])->assertOk()->assertJson(['status' => 200, 'message' => 'OK']);
        Http::assertNothingSent();
    }
}
