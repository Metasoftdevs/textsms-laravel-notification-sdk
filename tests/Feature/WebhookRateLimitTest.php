<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\TestCase;
use Mockery;

final class WebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $defaults = require __DIR__.'/../../config/textsms.php';
        $app['config']->set('textsms.webhook', $defaults['webhook']);
        $app['config']->set('cache.default', 'array');
    }

    private function expectProcessorCalls(int $calls): void
    {
        Http::preventStrayRequests();
        $processor = Mockery::mock(SmsDeliveryReportProcessor::class);
        $processor->shouldReceive('recordDeliveryReport')->times($calls)
            ->with(['messageid' => 'synthetic-rate-id', 'status' => 'Delivered'])->andReturnNull();
        $this->app->instance(SmsDeliveryReportProcessor::class, $processor);
    }

    private function postCallback(string $token = 'test-dlr-secret'): TestResponse
    {
        return $this->postJson('/api/v1/sms/textsms/dlr?token='.$token, [
            'messageid' => 'synthetic-rate-id', 'status' => 'Delivered',
        ]);
    }

    public function test_the_real_default_allows_six_hundred_callbacks_then_returns_429(): void
    {
        $this->expectProcessorCalls(600);
        self::assertSame(600, config('textsms.webhook.rate_limit_per_minute'));
        for ($i = 0; $i < 600; $i++) {
            $this->postCallback()->assertOk();
        }
        $this->postCallback()->assertStatus(429)->assertHeader('Retry-After');
        Http::assertNothingSent();
    }

    public function test_configured_limits_share_one_integration_bucket_across_source_ips(): void
    {
        config()->set('textsms.webhook.rate_limit_per_minute', 2);
        $this->expectProcessorCalls(2);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->postCallback()->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11'])->postCallback()->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12'])->postCallback()->assertStatus(429);
    }

    public function test_authentication_precedes_the_limiter_even_when_priority_sorted_middleware_is_present(): void
    {
        config()->set('textsms.webhook.rate_limit_per_minute', 2);
        $route = $this->app->make(Router::class)->getRoutes()->getByName('metasoftdevs.textsms.dlr');
        $route->middleware(SubstituteBindings::class);
        $this->expectProcessorCalls(2);
        for ($i = 0; $i < 3; $i++) {
            $this->postCallback('invalid-token')->assertStatus(401);
        }
        $this->postCallback()->assertOk();
        $this->postCallback()->assertOk();
        $this->postCallback()->assertStatus(429);
        $this->postCallback('invalid-token')->assertStatus(401);
        $this->postJson('/api/v1/sms/textsms/dlr', ['messageid' => 'synthetic-rate-id'])->assertStatus(401);
    }

    public function test_the_env_configured_callback_url_secret_and_rate_limit_are_used(): void
    {
        $previousEnv = $_ENV;
        $previousServer = $_SERVER;
        try {
            $_ENV['TEXTSMS_DLR_SECRET'] = $_SERVER['TEXTSMS_DLR_SECRET'] = 'synthetic-env-url-secret';
            $_ENV['TEXTSMS_WEBHOOK_RATE_LIMIT'] = $_SERVER['TEXTSMS_WEBHOOK_RATE_LIMIT'] = '2';
            $settings = require __DIR__.'/../../config/textsms.php';
            self::assertSame(2, $settings['webhook']['rate_limit_per_minute'] ?? null);
            config()->set('textsms.providers.textsms.dlr_secret', $settings['providers']['textsms']['dlr_secret']);
            config()->set('textsms.webhook.rate_limit_per_minute', $settings['webhook']['rate_limit_per_minute']);
            $this->expectProcessorCalls(2);
            $this->postCallback('synthetic-env-url-secret')->assertOk();
            $this->postCallback('synthetic-env-url-secret')->assertOk();
            $this->postCallback('synthetic-env-url-secret')->assertStatus(429);
        } finally {
            $_ENV = $previousEnv;
            $_SERVER = $previousServer;
        }
    }
}
