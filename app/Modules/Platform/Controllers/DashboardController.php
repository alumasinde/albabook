<?php

declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Modules\Auth\PlatformPermission;
use App\Modules\Billing\Services\FeatureGate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Platform\Context\PlatformAuthContext;
use App\Modules\Platform\Services\SupportSessionService;
use App\Tenancy\TenantRepository;

/**
 * Platform overview: tenant directory, status counts and the administrator's
 * recent support sessions. Admin identity never grants tenant permissions.
 */
final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly PlatformAuthContext $auth,
        private readonly TenantRepository $tenants,
        private readonly SupportSessionService $support,
        private readonly FeatureGate $features,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        return $this->view->response('admin/dashboard.twig', [
            'tenants' => $this->tenants->listAllWithSubscription(),
            'counts' => $this->tenants->countsByStatus(),
            'plans' => $this->features->plans(),
            'canBilling' => $this->auth->can(PlatformPermission::BillingManage->value),
            'supportSessions' => $this->support->recentForAdmin($this->auth),
            'canSupportRead' => $this->auth->can(PlatformPermission::SupportRead->value),
            'canSupportWrite' => $this->auth->can(PlatformPermission::SupportWrite->value),
            'status' => $request->query('status', ''),
            'active_nav' => 'overview',
            'page_title' => 'Platform overview',
            'admin_email' => $this->auth->user?->email,
        ]);
    }
}
