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
    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly \App\Modules\Tenants\Services\SetupReadiness $readiness,
    ) {}

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

        $summary = $this->metrics->summary(
            $tenant->tenantId,
            $tenant->timezone,
            $tenant->currency,
            $membership->branchIds,
            $membership->can('reports.view_financial'),
            $membership->can('appointments.confirm'),
        );
        // Setup checklist (update.md 2.3) for people who can act on it;
        // hidden once every item is done.
        $summary['setup'] = null;
        if ($membership->can('booking_page.manage') || $membership->can('settings.update')) {
            $setup = $this->readiness->check($tenant->tenantId);
            $summary['setup'] = $setup['done'] < $setup['total'] ? $setup : null;
        }

        return ApiResponse::ok($summary);
    }
}
