<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsSender;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Events\SmsDispatchAccepted;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Exceptions\SmsProviderException;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Messages\SmsMessage;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Models\SmsDispatch;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Event::fake([SmsDispatchAccepted::class]);
});

/** @return Collection<int, SmsDispatch> */
function invokeRetryPolicySend(bool $bulk): Collection
{
    $sender = app(SmsSender::class);

    return $bulk ? $sender->sendBulk([
        ['recipient' => '0700000000', 'message' => new SmsMessage('Synthetic retry', clientReference: 'R')],
        ['recipient' => '0700000000', 'message' => new SmsMessage('Synthetic retry sibling', clientReference: 'S')],
    ]) : collect([$sender->send('0700000000', new SmsMessage('Synthetic retry', clientReference: 'R'))]);
}

/** @return array<string, mixed> */
function retryPolicySuccess(bool $bulk): array
{
    $rows = [['clientsmsid' => 'R', 'respose-code' => 200, 'messageid' => 'retry-R']];
    if ($bulk) {
        $rows[] = ['clientsmsid' => 'S', 'respose-code' => 200, 'messageid' => 'retry-S'];
    }

    return ['responses' => $rows];
}

function retryPolicyFailure(mixed $errno, bool $requestException = false): Throwable
{
    $request = new Request('POST', 'https://sms.textsms.co.ke/api/services/sendsms/');
    $context = $errno === null ? [] : ['errno' => $errno];

    if ($requestException) {
        return method_exists(GuzzleRequestException::class, 'getHandlerContext')
            ? new GuzzleRequestException('Synthetic transport failure', $request, null, null, $context)
            : GuzzleRequestException::create($request);
    }

    return method_exists(ConnectException::class, 'getHandlerContext')
        ? new ConnectException('cURL error 7: Synthetic connection failure', $request, null, $context)
        : new ConnectException('cURL error 7: Synthetic connection failure', $request);
}

it('recovers with one retry only for structured pre-send connection evidence', function (int $errno, bool $requestException, bool $bulk): void {
    $attempts = 0;
    Http::fake(function ($request, array $options) use (&$attempts, $errno, $requestException, $bulk) {
        expect($options['allow_redirects'] ?? null)->toBeFalse();
        if (++$attempts === 1) {
            if (! method_exists(ConnectException::class, 'getHandlerContext')) {
                ($options['on_stats'])(new TransferStats(new Request('POST', $request->url()), null, 0.01, $errno));
            }
            throw retryPolicyFailure($errno, $requestException);
        }

        return Http::response(retryPolicySuccess($bulk));
    });

    $rows = invokeRetryPolicySend($bulk);
    expect($attempts)->toBe(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['sent'])
        ->and(SmsDispatch::count())->toBe($bulk ? 2 : 1);
    Event::assertDispatchedTimes(SmsDispatchAccepted::class, $bulk ? 2 : 1);
    Http::assertSentCount(2);
})->with(['DNS' => [6, false], 'connect' => [7, false], 'DNS request exception' => [6, true], 'connect request exception' => [7, true]])
    ->with(['single' => [false], 'bulk' => [true]]);

it('stops after the one permitted retry when connection establishment keeps failing', function (int $errno, bool $bulk): void {
    $attempts = 0;
    Http::fake(function ($request, array $options) use (&$attempts, $errno) {
        $attempts++;
        if (! method_exists(ConnectException::class, 'getHandlerContext')) {
            ($options['on_stats'])(new TransferStats(new Request('POST', $request->url()), null, 0.01, $errno));
        }
        throw retryPolicyFailure($errno);
    });

    expect(fn () => invokeRetryPolicySend($bulk))->toThrow(SmsProviderException::class);
    expect($attempts)->toBe(2)
        ->and(SmsDispatch::pluck('status')->all())->toBe($bulk ? ['failed', 'failed'] : ['failed']);
    Http::assertSentCount(2);
})->with([6, 7])->with(['single' => [false], 'bulk' => [true]]);

it('does not retry other or unproven connection failures even when their text mentions error seven', function (mixed $errno, bool $bulk): void {
    $attempts = 0;
    Http::fake(function () use (&$attempts, $errno) {
        $attempts++;
        throw $errno === 'no-cause'
            ? new ConnectionException('cURL error 7: No structured cause')
            : retryPolicyFailure($errno);
    });

    expect(fn () => invokeRetryPolicySend($bulk))->toThrow(SmsProviderException::class);
    expect($attempts)->toBe(1)
        ->and(SmsDispatch::count())->toBe($bulk ? 2 : 1)
        ->and(SmsDispatch::firstOrFail()->metadata['provider_diagnostics']['provider_acceptance'])->toBe('unknown');
    Event::assertNotDispatched(SmsDispatchAccepted::class);
})->with(['timeout' => [28], 'empty reply' => [52], 'TLS failure' => [35], 'missing context' => [null], 'wrong context type' => ['7'], 'no cause' => ['no-cause']])
    ->with(['single' => [false], 'bulk' => [true]]);

it('does not retry a received HTTP response', function (int $status, bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response(['response-code' => 1004], $status)]);

    expect(fn () => invokeRetryPolicySend($bulk))->toThrow(SmsProviderException::class);
    Http::assertSentCount(1);
})->with([422, 429, 500, 503])->with(['single' => [false], 'bulk' => [true]]);

it('leaves the existing balance retry behavior separate from sending', function (): void {
    Http::fakeSequence()->push([], 503)->push(['credit' => 42]);
    expect(app(SmsSender::class)->balance())->toBe(['credit' => 42]);
    Http::assertSentCount(2);
});

it('retries proven cURL transfer statistics when the exception has no handler context', function (int $errno, bool $bulk): void {
    $attempts = 0;
    Http::fake(function ($request, array $options) use (&$attempts, $errno, $bulk) {
        if (++$attempts === 1) {
            ($options['on_stats'])(new TransferStats(new Request('POST', $request->url()), null, 0.01, $errno));
            throw new ConnectionException('Synthetic connection failure without legacy context');
        }

        return Http::response(retryPolicySuccess($bulk));
    });
    $rows = invokeRetryPolicySend($bulk);
    expect($attempts)->toBe(2)->and($rows->pluck('status')->unique()->all())->toBe(['sent']);
})->with([6, 7])->with(['single' => [false], 'bulk' => [true]]);

it('does not infer retry permission from ambiguous statistics or a received response', function (mixed $error, bool $hasResponse): void {
    $attempts = 0;
    Http::fake(function ($request, array $options) use (&$attempts, $error, $hasResponse) {
        $attempts++;
        ($options['on_stats'])(new TransferStats(new Request('POST', $request->url()), $hasResponse ? new Response(200) : null, 0.01, $error));
        throw retryPolicyFailure(7);
    });
    expect(fn () => invokeRetryPolicySend(false))->toThrow(SmsProviderException::class);
    expect($attempts)->toBe(1);
})->with(['timeout' => [28, false], 'empty reply' => [52, false], 'missing' => [null, false], 'string' => ['7', false], 'response received' => [7, true]]);

it('records an unfollowed redirect as unknown without retrying or leaking a type error', function (int $status, bool $bulk): void {
    Http::fake(['sms.textsms.co.ke/*' => Http::response('', $status, ['Location' => 'https://example.invalid/send'])]);

    expect(fn () => invokeRetryPolicySend($bulk))->toThrow(SmsProviderException::class);
    Http::assertSentCount(1);
    expect(SmsDispatch::pluck('status')->all())->toBe($bulk ? ['failed', 'failed'] : ['failed']);
    foreach (SmsDispatch::all() as $row) {
        expect($row->metadata['provider_diagnostics']['provider_acceptance'])->toBe('unknown');
    }
})->with([301, 302, 307, 308])->with(['single' => [false], 'bulk' => [true]]);
