<?php

declare(strict_types=1);

namespace MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Contracts\SmsDeliveryReportProcessor;
use MetaSoftDevs\TextSmsLaravelNotificationSdk\Http\Middleware\VerifyTextSmsCallback;
use Psr\Log\LoggerInterface;

/**
 * Receives TextSMS delivery-report callbacks.
 *
 * The endpoint is intentionally **tolerant** of unknown ids and **strict**
 * about payload shape:
 *
 *  - Unknown `messageid` → HTTP 200 by SDK policy. Provider retry behavior
 *    and the live push callback contract remain unverified here.
 *  - Missing, blank, or wrongly typed `messageid` / `messageID`, or a
 *    `status` / `dlrstatus` that is an array, object or boolean → HTTP 422 and one PSR-3
 *    warning that lists payload **keys only** (never values). The
 *    processor is not invoked, so no row changes and no event fires.
 *  - Well-formed payload → HTTP 200 with the canonical `{status, message}`
 *    envelope after recording returns normally. The default processor stores
 *    only the payload, without model events; storage exceptions propagate.
 *
 * The shared-secret `token` is removed from the payload before it reaches
 * the processor, so the secret is never persisted in `dlr_payload` or
 * logged by a processor. This is the
 * ingress guarantee; direct callers of the processor must sanitise their
 * own input (see {@see SmsDeliveryReportProcessor}).
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
    /** Resolve inbound report processing and ingress diagnostics. */
    public function __construct(
        private readonly SmsDeliveryReportProcessor $processor,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Handle a single delivery-report callback.
     */
    public function dlr(Request $request): JsonResponse
    {
        $payload = $request->except('token');

        $problem = $this->payloadProblem($payload);

        if ($problem !== null) {
            $this->logger->warning('TextSMS DLR payload rejected', [
                'reason' => $problem,
                'payload_keys' => array_keys($payload),
            ]);

            return response()->json(['status' => 422, 'message' => $problem], 422);
        }

        $this->processor->recordDeliveryReport($payload);

        return response()->json(['status' => 200, 'message' => 'OK']);
    }

    /**
     * Validate callback identifiers and informational status shapes.
     * Prefer `messageid` before `messageID`, `status`
     * before `dlrstatus`.
     *
     * A message id must be an integer or a non-blank string; `0` and `"0"`
     * are valid ids. A status may be absent or any string or number; it is
     * retained as information without classification.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null The rejection reason, or null when the shape is valid.
     */
    private function payloadProblem(array $payload): ?string
    {
        $messageId = $payload['messageid'] ?? $payload['messageID'] ?? null;

        if ($messageId === null || (is_string($messageId) && trim($messageId) === '')) {
            return 'Missing messageid in payload';
        }

        if (! is_int($messageId) && ! is_string($messageId)) {
            return 'Invalid messageid in payload';
        }

        $status = $payload['status'] ?? $payload['dlrstatus'] ?? null;

        if ($status !== null && ! is_string($status) && ! is_int($status) && ! is_float($status)) {
            return 'Invalid status in payload';
        }

        return null;
    }
}
