<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Repositories\BillingRepository;
use App\Modules\Billing\Services\SubscriptionBillingService;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for billing (session-authenticated, D-008).
 * Reads: subscription, plan features, invoices, payments.
 * Writes (billing.manage): choose a plan, start an M-Pesa payment, poll it.
 */
final class BillingApiController
{
    public function __construct(
        private readonly BillingRepository $billing,
        private readonly SubscriptionBillingService $subscriptions,
    ) {}

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
            'plans' => $this->subscriptions->planChoices(),
            'can_manage' => true, // billing.view is the only granted level today
        ]);
    }

    /**
     * Choose a plan: upgrade issues a prorated invoice, downgrade/trial
     * choice schedules the change, expired workspaces get an activation
     * invoice and grace until it is paid.
     *
     * @param array<string, string> $params
     */
    public function changePlan(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('billing.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage billing.', 403);
        }

        $plan = trim((string) $request->input('plan', ''));
        try {
            $result = $this->subscriptions->choosePlan($tenant->tenantId, $plan, $membership->userId);
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields);
        }

        return ApiResponse::ok($result);
    }

    /**
     * Start an M-Pesa STK prompt for an open invoice.
     * @param array<string, string> $params
     */
    public function pay(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('billing.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage billing.', 403);
        }

        try {
            $result = $this->subscriptions->payWithMpesa(
                $tenant->tenantId,
                trim((string) $request->input('invoice_uuid', '')),
                trim((string) $request->input('phone', '')),
                $membership->userId,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields);
        }

        return ApiResponse::ok($result);
    }

    /**
     * Poll a payment prompt's status (queries Daraja when the callback lags).
     * @param array<string, string> $params
     */
    public function paymentStatus(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('billing.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view billing.', 403);
        }

        try {
            $result = $this->subscriptions->paymentStatus($tenant->tenantId, (string) ($params['uuid'] ?? ''));
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields);
        }

        return ApiResponse::ok($result);
    }

    /** @return array{TenantContext, MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }
}
