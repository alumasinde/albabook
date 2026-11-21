<?php

declare(strict_types=1);

namespace App\Modules\Staff\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * Renders the staff directory shell. Tenant + membership always come from
 * TenantMiddleware; list data hydrates via the JSON API.
 */
final class StaffPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('staff.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view staff.');
        }

        return $this->view->response('staff/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
