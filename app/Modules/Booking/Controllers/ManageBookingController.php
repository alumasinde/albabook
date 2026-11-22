<?php

declare(strict_types=1);

namespace App\Modules\Booking\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View\View;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Tenancy\TenantRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Security\RateLimiter;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Time\DateTimeService;

/**
 * Customer self-service (booking-engine.md §18, decisions.md D-008): the
 * manage token is the capability — it identifies the appointment globally,
 * so endpoints are slug-scoped for UX but authorised by the token alone.
 * Cancel and reschedule obey cancellation_cutoff_minutes /
 * reschedule_cutoff_minutes / max_reschedules from booking_policies.
 */
final class ManageBookingController
{
    private const RATE_LIMIT = 12;
    private const RATE_WINDOW = 600;

    public function __construct(
        private readonly View $view,
        private readonly TenantRepository $tenants,
        private readonly AppointmentRepository $appointments,
        private readonly BookingPageRepository $pages,
        private readonly ManualBookingService $bookings,
        private readonly AppointmentTransitionService $transitions,
        private readonly DateTimeService $dates,
        private readonly CurrencyFormatter $currency,
        private readonly RateLimiter $limiter,
        private readonly \App\Core\Http\RequestContext $context,
    ) {}

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        $resolved = $this->resolve($params);
        if ($resolved === null) {
            return $this->view->response('booking/manage-invalid.twig', [], 404);
        }
        [$tenant, $appointment] = $resolved;

        $data = $this->viewData($tenant, $appointment);

        return $this->view->response('booking/manage.twig', [
            'tenant' => $tenant,
            // Slugless endpoints on a verified custom domain.
            'base' => ($this->context->customDomain !== null ? '' : '/' . $tenant['slug']) . '/manage/' . $params['token'],
            'appointment' => $appointment,
            'view' => $data,
            'token' => (string) $params['token'],
        ]);
    }

    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params): Response
    {
        $resolved = $this->resolve($params);
        if ($resolved === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }
        if (!$this->allowRequest($request)) {
            return ApiResponse::error('RATE_LIMITED', 'Too many attempts. Try again shortly.', 429);
        }
        [$tenant, $appointment] = $resolved;
        $tenantId = (int) $tenant['id'];

        if (!in_array((string) $appointment['status'], ['pending', 'confirmed'], true)) {
            return ApiResponse::error('INVALID_TRANSITION', 'This booking can no longer be cancelled.', 409);
        }
        $policy = $this->pages->policy($tenantId, (int) $appointment['branch_id']) ?? [];
        $cutoff = isset($policy['cancellation_cutoff_minutes']) ? (int) $policy['cancellation_cutoff_minutes'] : null;
        if ($cutoff !== null) {
            $start = new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC'));
            if ($this->dates->now() > $start->modify("-{$cutoff} minutes")) {
                return ApiResponse::error('CUTOFF_PASSED', 'It is too late to cancel online — contact the business.', 409, ['time' => 'Cancellation deadline passed.']);
            }
        }

        $body = $request->body();
        $reason = isset($body['reason']) ? mb_substr(trim((string) $body['reason']), 0, 255) : null;

        try {
            $row = $this->transitions->transition(
                $tenantId,
                (string) $appointment['uuid'],
                'cancelled',
                null,
                null,
                'customer',
                $reason,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok(['status' => $row['status']]);
    }

    /** @param array<string, string> $params */
    public function reschedule(Request $request, array $params): Response
    {
        $resolved = $this->resolve($params);
        if ($resolved === null) {
            return ApiResponse::error('NOT_FOUND', 'Booking not found.', 404);
        }
        if (!$this->allowRequest($request)) {
            return ApiResponse::error('RATE_LIMITED', 'Too many attempts. Try again shortly.', 429);
        }
        [$tenant, $appointment] = $resolved;

        $body = $request->body();
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        $staffId = isset($body['staff_id']) && (int) $body['staff_id'] > 0 ? (int) $body['staff_id'] : null;

        $fields = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Pick a valid date.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fields['time'] = 'Pick a time.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Please fix the highlighted fields.', 422, $fields);
        }

        try {
            $result = $this->bookings->reschedule(
                (int) $tenant['id'],
                (string) $appointment['uuid'],
                $date,
                $time,
                $staffId,
                null,
                null,
                'customer',
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($result);
    }

    /**
     * Token + slug resolution. The token is authoritative (it identifies the
     * appointment); the slug must still name the same tenant so URLs cannot
     * be replayed under a different business's page.
     * @param array<string, string> $params
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function resolve(array $params): ?array
    {
        $slug = strtolower(trim((string) ($params['slug'] ?? '')));
        // Slugless manage path on a verified custom domain.
        $tenant = $slug === ''
            ? ($this->context->customDomain !== null ? $this->tenants->findByDomain($this->context->customDomain) : null)
            : $this->tenants->findBySlug($slug);
        if ($tenant === null) {
            return null;
        }
        $token = trim((string) ($params['token'] ?? ''));
        if (strlen($token) !== 48 || !ctype_xdigit($token)) {
            return null;
        }
        $appointment = $this->appointments->findByManageToken(hash('sha256', $token));
        if ($appointment === null || (int) $appointment['tenant_id'] !== (int) $tenant['id']) {
            return null;
        }

        return [$tenant, $appointment];
    }

    private function allowRequest(Request $request): bool
    {
        return $this->limiter
            ->hit('manage:' . $request->clientIp(), self::RATE_LIMIT, self::RATE_WINDOW)
            ->allowed;
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $appointment
     * @return array<string, mixed>
     */
    private function viewData(array $tenant, array $appointment): array
    {
        $tenantId = (int) $tenant['id'];
        $items = $this->appointments->itemsFor($tenantId, (int) $appointment['id']);
        $item = $items[0] ?? [];
        $tz = (string) ($tenant['timezone'] ?: 'Africa/Nairobi');
        $startLocal = $this->dates->toLocal($this->dates->fromDb((string) $appointment['start_at']), $tz);

        $policy = $this->pages->policy($tenantId, (int) $appointment['branch_id']) ?? [];
        $status = (string) $appointment['status'];
        $live = in_array($status, ['pending', 'confirmed'], true);
        $start = new \DateTimeImmutable((string) $appointment['start_at'], new \DateTimeZone('UTC'));

        $canCancel = $live;
        $canReschedule = $live;
        if ($live && isset($policy['cancellation_cutoff_minutes'])) {
            $canCancel = $this->dates->now() <= $start->modify('-' . (int) $policy['cancellation_cutoff_minutes'] . ' minutes');
        }
        if ($live && isset($policy['reschedule_cutoff_minutes'])) {
            $canReschedule = $this->dates->now() <= $start->modify('-' . (int) $policy['reschedule_cutoff_minutes'] . ' minutes');
        }
        if ($live && isset($policy['max_reschedules'])) {
            $canReschedule = $canReschedule && (int) $appointment['reschedule_count'] < (int) $policy['max_reschedules'];
        }

        $price = new Money((int) $appointment['total_amount_minor'], (string) $appointment['currency']);

        return [
            'reference' => (string) $appointment['reference'],
            'status' => $status,
            'date_label' => $startLocal->format('l, j F Y'),
            'time_label' => $startLocal->format('H:i'),
            'timezone' => $tz,
            'service_name' => (string) ($item['service_name_snapshot'] ?? ''),
            'staff_name' => (string) ($item['staff_name'] ?? ''),
            'duration' => (int) ($item['duration_minutes'] ?? 0),
            'price_label' => $this->currency->format($price),
            'branch_id' => (int) $appointment['branch_id'],
            'service_id' => (int) ($item['service_id'] ?? 0),
            'staff_id' => (int) ($item['staff_id'] ?? 0),
            'can_cancel' => $canCancel,
            'can_reschedule' => $canReschedule,
            'reschedules_left' => isset($policy['max_reschedules'])
                ? max(0, (int) $policy['max_reschedules'] - (int) $appointment['reschedule_count'])
                : null,
        ];
    }
}
