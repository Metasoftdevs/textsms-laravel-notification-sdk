<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\BalanceCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\ShowDispatchCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Console\Commands\TestSendCommand;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\PhoneNumberNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Gateways\TextSmsGateway;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware\VerifyTextSmsCallback;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\ChainedNormalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\PhoneNumbers\KenyanE164Normalizer;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\TextSmsNotificationServiceProvider;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('shares one default gateway instance between sending and report processing', function (): void {
    $sender = app(SmsSender::class);
    expect($sender)->toBeInstanceOf(TextSmsGateway::class)
        ->and(app(SmsSender::class))->toBe($sender)
        ->and(app(SmsDeliveryReportProcessor::class))->toBe($sender)
        ->and(app(PhoneNumberNormalizer::class))->toBeInstanceOf(KenyanE164Normalizer::class);
    Http::assertNothingSent();
});

it('requires report support from a custom sender unless a separate processor is bound', function (): void {
    $sender = Mockery::mock(SmsSender::class);
    app()->instance($sender::class, $sender);
    config()->set('textsms.default_sender', $sender::class);

    expect(app(SmsSender::class))->toBe($sender);
    expect(fn () => app(SmsDeliveryReportProcessor::class))->toThrow(RuntimeException::class, 'must also implement');

    $processor = Mockery::mock(SmsDeliveryReportProcessor::class);
    app()->instance(SmsDeliveryReportProcessor::class, $processor);
    expect(app(SmsDeliveryReportProcessor::class))->toBe($processor)
        ->and(app(SmsSender::class))->toBe($sender);
    Http::assertNothingSent();
});

it('resolves the configured phone normalizer through the container', function (): void {
    $chain = new ChainedNormalizer([new KenyanE164Normalizer]);
    app()->instance(ChainedNormalizer::class, $chain);
    config()->set('textsms.phone_normalizer', ChainedNormalizer::class);

    expect(app(PhoneNumberNormalizer::class))->toBe($chain)
        ->and(app(PhoneNumberNormalizer::class)->normalize('0700000000'))->toBe('254700000000');
});

it('registers the callback middleware alias and public publication groups', function (): void {
    expect(app(Router::class)->getMiddleware()['verify.textsms.callback'] ?? null)->toBe(VerifyTextSmsCallback::class);
    foreach (['textsms-config', 'textsms-routes', 'textsms-migrations'] as $tag) {
        $paths = ServiceProvider::pathsToPublish(TextSmsNotificationServiceProvider::class, $tag);
        expect($paths)->toHaveCount(1)
            ->and(file_exists(array_key_first($paths)))->toBeTrue();
    }
});

it('registers the three shipped commands in the console application', function (): void {
    $commands = Artisan::all();
    expect($commands['textsms:balance'] ?? null)->toBeInstanceOf(BalanceCommand::class)
        ->and($commands['textsms:test'] ?? null)->toBeInstanceOf(TestSendCommand::class)
        ->and($commands['textsms:dispatch:show'] ?? null)->toBeInstanceOf(ShowDispatchCommand::class);
    Http::assertNothingSent();
});

it('does not type error when a host supplies a contract-only HTTP kernel', function (): void {
    $kernel = Mockery::mock(HttpKernelContract::class);
    app()->instance(HttpKernelContract::class, $kernel);
    app(HttpKernelContract::class);

    expect(fn () => (new TextSmsNotificationServiceProvider(app()))->boot())->not->toThrow(TypeError::class);
});
