<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Controllers;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Tenancy\TenantContext;

/**
 * Renders the appointments and calendar shells. Tenant + membership always
 * come from TenantMiddleware; the heavy data arrives via the JSON API.
 */
final class AppointmentsPageController
{
    public function __construct(
        private readonly View $view,
        private readonly AppointmentRepository $appointments,
        private readonly FeatureGate $features,
    ) {}

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
            'booking_cap' => $this->bookingCap($request),
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
            'booking_cap' => $this->bookingCap($request),
        ]);
    }

    /** @return array{used: int, limit: ?int, reached: bool} */
    private function bookingCap(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        assert($tenant instanceof TenantContext);
        $used = $this->appointments->countCreatedSince($tenant->tenantId, gmdate('Y-m-01 00:00:00'));

        return [
            'used' => $used,
            'limit' => $this->features->limit($tenant->tenantId, 'monthly_bookings'),
            'reached' => !$this->features->underLimit($tenant->tenantId, 'monthly_bookings', $used),
        ];
    }
}
