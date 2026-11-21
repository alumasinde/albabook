<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Dashboard\Services\DashboardMetricsService;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Overview widgets: one optimised summary request; charts can load
 * asynchronously (dashboard.md §8).
 */
final class DashboardApiController
{
    public function __construct(private readonly DashboardMetricsService $metrics) {}

    /** @param array<string, string> $params */
    public function summary(Request $request, array $params): Response
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            return ApiResponse::error('FORBIDDEN', 'No workspace is available for this request.', 403);
        }
        if (!$membership->can('dashboard.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view the dashboard.', 403);
        }

        return ApiResponse::ok($this->metrics->summary(
            $tenant->tenantId,
            $tenant->timezone,
            $tenant->currency,
            $membership->branchIds,
            $membership->can('reports.view_financial'),
        ));
    }
}
