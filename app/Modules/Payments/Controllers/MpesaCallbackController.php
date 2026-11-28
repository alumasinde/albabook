<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Payments\Mpesa\MpesaStkService;

/**
 * Safaricom STK callback receiver (api host, no session, no CSRF).
 * POST /webhooks/mpesa/stk/{account}/{signature}
 *
 * Answers Daraja's expected JSON. A bad signature or unknown account gets a
 * plain 404 so the endpoint reveals nothing to scanners.
 */
final class MpesaCallbackController
{
    public function __construct(private readonly MpesaStkService $stk) {}

    /** @param array<string, string> $params */
    public function stk(Request $request, array $params): Response
    {
        $accepted = $this->stk->handleCallback(
            (string) ($params['account'] ?? ''),
            (string) ($params['signature'] ?? ''),
            $request->body(),
            $request->clientIp() ?: '',
        );
        if (!$accepted) {
            return Response::json(['ResultCode' => 1, 'ResultDesc' => 'Rejected'], 404);
        }

        return Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
