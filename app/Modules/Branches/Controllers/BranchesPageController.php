<?php

declare(strict_types=1);

namespace App\Modules\Branches\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * Renders the branches shell. Data hydrates via the JSON API; tenant +
 * membership always come from TenantMiddleware.
 */
final class BranchesPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('branches.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view branches.');
        }

        return $this->view->response('branches/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
