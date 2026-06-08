<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware\VerifyTextSmsCallback;

/**
 * Receives TextSMS delivery-report callbacks.
 *
 * The endpoint is intentionally **tolerant**:
 *
 *  - Unknown `messageid` → HTTP 200. We don't want the provider's retry
 *    loop hammering us when their ledger references a row we've already
 *    archived or never knew about.
 *  - Missing `messageid` → HTTP 422. Malformed payloads should leave a
 *    loud trail in ops logs so the misconfiguration is caught.
 *  - Anything else (well-formed payload, known messageid) → HTTP 200 with
 *    the canonical `{status, message}` envelope.
 *
 * Authentication is handled by
 * {@see VerifyTextSmsCallback}
 * — the controller assumes the request is already trusted.
 *
 * Benchmark: ported from
 * `app/Http/Controllers/Api/V1/Sms/TextSmsCallbackController.php` in
 * QPIMS, with the dependency switched from the merged `SmsGateway`
 * interface to the narrower {@see SmsDeliveryReportProcessor} per
 * Reflection §4.2.
 */
class TextSmsCallbackController extends Controller
{
    public function __construct(
        private readonly SmsDeliveryReportProcessor $processor,
    ) {}

    /**
     * Handle a single delivery-report POST.
     */
    public function dlr(Request $request): JsonResponse
    {
        $messageId = $request->input('messageid') ?? $request->input('messageID');

        if (empty($messageId)) {
            return response()->json([
                'status' => 422,
                'message' => 'Missing messageid in payload',
            ], 422);
        }

        $this->processor->recordDeliveryReport($request->all());

        return response()->json(['status' => 200, 'message' => 'OK']);
    }
}
