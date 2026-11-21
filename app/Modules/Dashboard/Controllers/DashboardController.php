<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Auth\MembershipContext;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\AuthContext;
use App\Tenancy\Support\SupportAccess;
use App\Tenancy\TenantContext;

/**
 * Dashboard shell for an authorized membership. Tenant and membership always
 * come from TenantMiddleware, never from the request (multi-tenancy.md §1).
 */
final class DashboardController
{
    public function __construct(
        private readonly View $view,
        private readonly AuthContext $auth,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        $supportAccess = $request->attribute('support_access');
        $user = $this->auth->user;

        $supported = $supportAccess instanceof SupportAccess;
        if (!$tenant instanceof TenantContext || (!$membership instanceof MembershipContext && !$supported)) {
            throw new HttpException(403, 'FORBIDDEN', 'No workspace is available for this request.');
        }

        return $this->view->response('dashboard/index.twig', [
            'tenant' => $tenant,
            'membership' => $membership instanceof MembershipContext ? $membership : null,
            'user' => $user,
        ]);
    }
}
