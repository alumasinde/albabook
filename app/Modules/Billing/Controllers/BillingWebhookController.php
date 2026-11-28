<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Billing\Services\SubscriptionBillingService;

/**
 * Safaricom callback receiver for subscription (AlbaBook plan) invoices.
 * POST /webhooks/billing/mpesa/{invoice}/{signature}
 *
 * Same contract as the till's mpesa callback: a bad signature or unknown
 * invoice gets a plain 404 so the endpoint reveals nothing to scanners.
 */
final class BillingWebhookController
{
    public function __construct(private readonly SubscriptionBillingService $billing) {}

    /** @param array<string, string> $params */
    public function mpesa(Request $request, array $params): Response
    {
        $accepted = $this->billing->handleCallback(
            (string) ($params['invoice'] ?? ''),
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
