<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Payments\Mpesa\MpesaStkService;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Till-side M-Pesa STK push (app host, session + CSRF).
 *   POST /api/appointments/{uuid}/mpesa/stk            send the prompt
 *   GET  /api/appointments/{uuid}/mpesa/stk/{request}  poll its status
 */
final class MpesaApiController
{
    public function __construct(private readonly MpesaStkService $stk) {}

    /** @param array<string, string> $params */
    public function start(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to take payments.', 403);
        }

        $body = $request->body();
        $amount = $body['amount_minor'] ?? null;
        $amount = is_int($amount) ? $amount : (is_string($amount) && preg_match('/^[1-9][0-9]{0,11}$/', $amount) === 1 ? (int) $amount : 0);
        $key = trim((string) ($body['idempotency_key'] ?? ''));
        if (mb_strlen($key) < 8 || mb_strlen($key) > 100 || preg_match('/[^\x20-\x7E]/', $key) === 1) {
            return ApiResponse::error('VALIDATION', 'A payment idempotency key is required.', 422, ['idempotency_key' => 'Required.']);
        }

        try {
            $result = $this->stk->initiate(
                $tenant->tenantId,
                (string) $params['uuid'],
                $membership->branchIds,
                (string) ($body['phone'] ?? ''),
                $amount,
                $key,
                $membership->actorUserId(),
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($result, 201);
    }

    /** @param array<string, string> $params */
    public function status(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('payments.view') && !$membership->can('payments.record')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view payments.', 403);
        }

        try {
            return ApiResponse::ok($this->stk->refresh($tenant->tenantId, (string) $params['request'], $membership->branchIds));
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        } catch (\App\Modules\Payments\Mpesa\DarajaException) {
            // A failed status query is not a failed payment — keep polling.
            return ApiResponse::ok(['status' => 'pending', 'done' => false, 'message' => 'Still waiting for M-Pesa…']);
        }
    }

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        return [$request->attribute('tenant'), $request->attribute('membership')];
    }
}
