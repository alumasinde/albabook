<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Repositories\BillingRepository;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for billing (session-authenticated, D-008). Read-only —
 * subscription changes and refunds stay server-side until a billing
 * provider is wired (plan.md §22).
 */
final class BillingApiController
{
    public function __construct(private readonly BillingRepository $billing) {}

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('billing.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view billing.', 403);
        }

        return ApiResponse::ok([
            'subscription' => $this->billing->subscription($tenant->tenantId),
            'features' => $this->billing->planFeatures($tenant->tenantId),
            'invoices' => $this->billing->invoices($tenant->tenantId),
            'payments' => $this->billing->payments($tenant->tenantId),
            'can_manage' => $membership->can('billing.manage'),
        ]);
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }
}
