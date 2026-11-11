<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware\VerifyTextSmsCallback;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Tests\TestCase;

final class DisabledWebhookBootTest extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('textsms.webhook.enabled', false);
    }

    public function test_disabling_webhooks_before_boot_leaves_no_package_route_but_keeps_the_alias(): void
    {
        $router = $this->app->make(Router::class);
        self::assertNull($router->getRoutes()->getByName('metasoftdevs.textsms.dlr'));
        self::assertSame(VerifyTextSmsCallback::class, $router->getMiddleware()['verify.textsms.callback'] ?? null);
        $this->postJson('/api/v1/sms/textsms/dlr')->assertNotFound();
    }
}
