<?php

declare(strict_types=1);

namespace App\Modules\Reports\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Tenancy\TenantContext;

/** Renders the reports shell; data hydrates via the JSON API. */
final class ReportsPageController
{
    public function __construct(
        private readonly View $view,
        private readonly FeatureGate $features,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('reports.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view reports.');
        }
        $tenant = $request->attribute('tenant');
        assert($tenant instanceof TenantContext);

        return $this->view->response('reports/index.twig', [
            'membership' => $membership,
            'tenant' => $tenant,
            'can_export' => $this->features->enabled($tenant->tenantId, 'advanced_reports'),
        ]);
    }
}
