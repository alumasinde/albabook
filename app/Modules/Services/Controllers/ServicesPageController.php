<?php

declare(strict_types=1);

namespace App\Modules\Services\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * Renders the services catalogue shell. Tenant + membership always come from
 * TenantMiddleware; list data hydrates via the JSON API.
 */
final class ServicesPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('services.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view services.');
        }

        return $this->view->response('services/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
