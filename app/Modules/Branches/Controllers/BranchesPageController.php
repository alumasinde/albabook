<?php

declare(strict_types=1);

namespace App\Modules\Branches\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Branches\Repositories\BranchRepository;
use App\Tenancy\TenantContext;

/**
 * Renders the branches shell. Data hydrates via the JSON API; tenant +
 * membership always come from TenantMiddleware.
 */
final class BranchesPageController
{
    public function __construct(
        private readonly View $view,
        private readonly BranchRepository $branches,
        private readonly FeatureGate $features,
    ) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('branches.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view branches.');
        }
        $tenant = $request->attribute('tenant');
        assert($tenant instanceof TenantContext);
        $used = $this->branches->countActive($tenant->tenantId);

        return $this->view->response('branches/index.twig', [
            'membership' => $membership,
            'tenant' => $tenant,
            'cap' => [
                'used' => $used,
                'limit' => $this->features->limit($tenant->tenantId, 'max_branches'),
                'reached' => !$this->features->underLimit($tenant->tenantId, 'max_branches', $used),
            ],
        ]);
    }
}
