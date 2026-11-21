<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Auth\Context\MembershipContext;

/**
 * Renders the appointments and calendar shells. Tenant + membership always
 * come from TenantMiddleware; the heavy data arrives via the JSON API.
 */
final class AppointmentsPageController
{
    public function __construct(private readonly View $view) {}

    /** @param array<string, string> $params */
    public function index(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('appointments.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view appointments.');
        }

        return $this->view->response('appointments/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }

    /** @param array<string, string> $params */
    public function calendar(Request $request, array $params): Response
    {
        $membership = $request->attribute('membership');
        if (!$membership instanceof MembershipContext || !$membership->can('appointments.view')) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to view appointments.');
        }

        return $this->view->response('calendar/index.twig', [
            'membership' => $membership,
            'tenant' => $request->attribute('tenant'),
        ]);
    }
}
