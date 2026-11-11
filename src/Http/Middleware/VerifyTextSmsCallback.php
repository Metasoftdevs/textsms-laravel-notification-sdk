<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the TextSMS delivery-report webhook against unauthorised callers.
 *
 * The SDK accepts a configured shared secret carried either as a `?token=` query parameter or an
 * `X-TextSMS-Token` header, compared in constant time via `hash_equals()`
 * to defeat timing attacks. Confirm the provider's callback registration
 * and token transport capabilities for the account before using this endpoint.
 *
 * Mismatch, absence, or a non-string token (for example `?token[]=…`) →
 * 401 Unauthorized + a PSR-3 warning log entry. Missing configuration →
 * 500 Server misconfiguration (so ops sees the failure loudly during
 * initial setup instead of silently accepting everything). Denial logs
 * never contain the presented token value.
 *
 * Benchmark: ported from
 * `app/Http/Middleware/Api/VerifyTextSmsCallback.php` in QPIMS, with the
 * application-coupled `ActivityLogger::log(...)` calls replaced by PSR-3
 * `LoggerInterface::warning(...)` per Reflection §3.2.
 */
class VerifyTextSmsCallback
{
    /** Route authentication failures through the application's logger. */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Compare the supplied token against the configured DLR secret. Allow
     * the request through when (and only when) the comparison succeeds.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('textsms.providers.textsms.dlr_secret', '');

        if ($expected === '') {
            $this->logDenial('TEXTSMS_DLR_SECRET is not configured', $request);

            return $this->denyResponse('Server misconfiguration', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // The header wins when present. A query value can be an array
        // (`?token[]=…`), so check the type before comparing.
        $supplied = $request->header('X-TextSMS-Token') ?? $request->query('token');

        if (! is_string($supplied) || $supplied === '' || ! hash_equals($expected, $supplied)) {
            $this->logDenial('Invalid or missing TextSMS callback token', $request);

            return $this->denyResponse('Unauthorized', Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Build the JSON denial response in a uniform shape so consumers
     * scraping logs see a consistent error envelope.
     */
    private function denyResponse(string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
        ], $status);
    }

    /**
     * Surface every denial through the PSR-3 logger so SIEM / ELK / Sentry
     * pipelines can alert on unexpected traffic to the webhook URL.
     *
     * The URL is logged without its query string and only the query
     * **keys** are recorded, so a presented `?token=` value (including a
     * stale secret during rotation) never reaches the log.
     *
     * @param  string  $reason  Human-readable reason recorded against the log entry.
     */
    private function logDenial(string $reason, Request $request): void
    {
        $this->logger->warning('TextSMS DLR access denied', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $request->url(),
            'query_keys' => array_keys($request->query()),
            'method' => $request->method(),
        ]);
    }
}
