<?php

declare(strict_types=1);

namespace App\Modules\Billing\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/** Renders the billing shell; data hydrates via the JSON API. */
final class BillingPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('billing.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view billing.');
        }

        return $this->view->response('billing/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
