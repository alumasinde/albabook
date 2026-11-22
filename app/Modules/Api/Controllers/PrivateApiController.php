<?php

declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Branches\Repositories\BranchRepository;
use App\Modules\Customers\Repositories\CustomerRepository;
use App\Modules\Reports\Repositories\ReportRepository;
use App\Modules\Services\Repositories\ServiceRepository;
use App\Modules\Staff\Repositories\StaffRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Time\DateTimeService;
use App\Tenancy\TenantRepository;

/**
 * Private tenant API (decisions.md D-008): /v1/* endpoints behind
 * ApiCredentialMiddleware — the tenant is the credential's tenant, so every
 * repository call stays tenant-scoped automatically. Public ids are uuids.
 */
final class PrivateApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly AppointmentRepository $appointments,
        private readonly ManualBookingService $bookings,
        private readonly AppointmentTransitionService $transitions,
        private readonly ServiceRepository $services,
        private readonly StaffRepository $staff,
        private readonly BranchRepository $branches,
        private readonly CustomerRepository $customers,
        private readonly ReportRepository $reports,
    ) {}

    /** @param array<string, string> $params */
    public function appointments(Request $request, array $params): Response
    {
        $tenantId = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $result = $this->appointments->listForTenant($tenantId, null, [
            'q' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
            'service' => (string) $request->query('service', ''),
            'staff' => (string) $request->query('staff', ''),
            'branch' => (string) $request->query('branch', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'date' => (string) $request->query('date', ''),
        ], $page, $perPage);

        return ApiResponse::ok([
            'appointments' => array_map([$this, 'appointmentPayload'], $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** @param array<string, string> $params */
    public function appointmentShow(Request $request, array $params): Response
    {
        $appointment = $this->appointments->findByUuid($this->tenantId($request), (string) $params['uuid']);
        if ($appointment === null) {
            return ApiResponse::error('NOT_FOUND', 'Appointment not found.', 404);
        }

        return ApiResponse::ok($this->appointmentPayload($appointment));
    }

    /** @param array<string, string> $params */
    public function appointmentCreate(Request $request, array $params): Response
    {
        $tenantId = $this->tenantId($request);
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null) {
            return ApiResponse::error('NOT_FOUND', 'Tenant not found.', 404);
        }
        $body = $request->body();

        $branch = isset($body['branch']) ? $this->branches->findByUuid($tenantId, (string) $body['branch']) : null;
        $service = isset($body['service']) ? $this->services->findByUuid($tenantId, (string) $body['service']) : null;
        $staffMember = isset($body['staff']) ? $this->staff->findByUuid($tenantId, (string) $body['staff']) : null;

        $fields = [];
        if ($branch === null) {
            $fields['branch'] = 'Unknown branch.';
        }
        if ($service === null) {
            $fields['service'] = 'Unknown service.';
        }
        if ($staffMember === null) {
            $fields['staff'] = 'Unknown staff.';
        }
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $fields['date'] = 'Valid Y-m-d required.';
        }
        if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            $fields['time'] = 'Valid H:i required.';
        }
        $customer = [
            'name' => trim((string) ($body['customer']['name'] ?? '')),
            'phone' => trim((string) ($body['customer']['phone'] ?? '')) ?: null,
            'email' => trim((string) ($body['customer']['email'] ?? '')) ?: null,
            'notes' => trim((string) ($body['customer']['notes'] ?? '')) ?: null,
        ];
        if ($customer['name'] === '') {
            $fields['customer.name'] = 'Customer name is required.';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION_FAILED', 'Check the highlighted fields.', 422, $fields);
        }
        // Populated field errors above guarantee all three are non-null here.
        if ($branch === null || $service === null || $staffMember === null) {
            return ApiResponse::error('VALIDATION_FAILED', 'Unknown resource reference.', 422);
        }

        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?? ''));
        if ($idempotencyKey !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $idempotencyKey)) {
            return ApiResponse::error('VALIDATION_FAILED', 'Invalid idempotency key.', 422, ['idempotency_key' => 'Invalid key.']);
        }

        try {
            $result = $this->bookings->create(
                $tenantId,
                (int) $branch['id'],
                (string) ($branch['timezone'] ?: $tenant['timezone']),
                (int) $staffMember['id'],
                (int) $service['id'],
                $date,
                $time,
                $customer,
                'api',
                'en',
                (string) $tenant['currency'],
                (int) $request->attribute('api_credential_id'),
                'api_credential',
                false,
                null,
                $idempotencyKey !== '' ? $idempotencyKey : null,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($result, $result['existing'] ? 200 : 201);
    }

    /** @param array<string, string> $params */
    public function appointmentConfirm(Request $request, array $params): Response
    {
        return $this->transition($request, (string) $params['uuid'], 'confirmed');
    }

    /** @param array<string, string> $params */
    public function appointmentCancel(Request $request, array $params): Response
    {
        $reason = isset($request->body()['reason']) ? mb_substr(trim((string) $request->body()['reason']), 0, 255) : null;

        return $this->transition($request, (string) $params['uuid'], 'cancelled', $reason);
    }

    /** @param array<string, string> $params */
    public function appointmentReschedule(Request $request, array $params): Response
    {
        $tenantId = $this->tenantId($request);
        $body = $request->body();
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return ApiResponse::error('VALIDATION_FAILED', 'date (Y-m-d) and time (H:i) are required.', 422);
        }
        $staffId = null;
        if (isset($body['staff']) && (string) $body['staff'] !== '') {
            $member = $this->staff->findByUuid($tenantId, (string) $body['staff']);
            if ($member === null) {
                return ApiResponse::error('VALIDATION_FAILED', 'Unknown staff.', 422, ['staff' => 'Choose a listed staff member.']);
            }
            $staffId = (int) $member['id'];
        }

        try {
            $result = $this->bookings->reschedule(
                $tenantId,
                (string) $params['uuid'],
                $date,
                $time,
                $staffId,
                isset($body['version']) ? (int) $body['version'] : null,
                (int) $request->attribute('api_credential_id'),
                'api_credential',
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($result);
    }

    /** @param array<string, string> $params */
    public function services(Request $request, array $params): Response
    {
        $result = $this->services->listForTenant($this->tenantId($request), [
            'q' => (string) $request->query('q', ''),
            'status' => 'active',
        ], 1, 500);

        return ApiResponse::ok([
            'services' => array_map(static fn(array $s): array => [
                'uuid' => (string) $s['uuid'],
                'name' => (string) $s['name'],
                'duration_minutes' => (int) $s['duration_minutes'],
                'price_minor' => $s['price_amount_minor'] !== null ? (int) $s['price_amount_minor'] : null,
                'currency' => (string) $s['currency'],
                'bookable_online' => (bool) $s['is_bookable_online'],
            ], $result['rows']),
        ]);
    }

    /** @param array<string, string> $params */
    public function staffList(Request $request, array $params): Response
    {
        $result = $this->staff->listForTenant($this->tenantId($request), [
            'q' => (string) $request->query('q', ''),
            'status' => 'active',
        ], 1, 500);

        return ApiResponse::ok([
            'staff' => array_map(static fn(array $s): array => [
                'uuid' => (string) $s['uuid'],
                'name' => (string) $s['display_name'],
            ], $result['rows']),
        ]);
    }

    /** @param array<string, string> $params */
    public function customers(Request $request, array $params): Response
    {
        $tenantId = $this->tenantId($request);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

        $result = $this->customers->listForTenant($tenantId, [
            'q' => (string) $request->query('q', ''),
        ], $page, $perPage);

        return ApiResponse::ok([
            'customers' => array_map(static fn(array $c): array => [
                'uuid' => (string) $c['uuid'],
                'name' => trim((string) $c['first_name'] . ' ' . (string) $c['last_name']),
                'phone' => $c['phone_e164'] !== null ? (string) $c['phone_e164'] : null,
                'email' => $c['email'] !== null ? (string) $c['email'] : null,
            ], $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /** @param array<string, string> $params */
    public function reportsSummary(Request $request, array $params): Response
    {
        $from = (string) $request->query('from', gmdate('Y-m-01'));
        $to = (string) $request->query('to', gmdate('Y-m-d', time() + 86400));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            return ApiResponse::error('VALIDATION_FAILED', 'from/to must be Y-m-d.', 422);
        }

        return ApiResponse::ok($this->reports->summary($this->tenantId($request), $from . ' 00:00:00', $to . ' 00:00:00'));
    }

    private function tenantId(Request $request): int
    {
        return (int) $request->attribute('api_tenant_id');
    }

    private function transition(Request $request, string $uuid, string $to, ?string $reason = null): Response
    {
        try {
            $row = $this->transitions->transition(
                $this->tenantId($request),
                $uuid,
                $to,
                null,
                (int) $request->attribute('api_credential_id'),
                'api_credential',
                $reason,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok(['uuid' => (string) $row['uuid'], 'status' => (string) $row['status']]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function appointmentPayload(array $row): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'reference' => (string) $row['reference'],
            'status' => (string) $row['status'],
            'payment_status' => (string) $row['payment_status'],
            'start_at' => (string) $row['start_at'],
            'end_at' => (string) $row['end_at'],
            'branch_name' => (string) ($row['branch_name'] ?? ''),
            'staff_name' => $row['staff_name'] !== null ? (string) $row['staff_name'] : null,
            'service_names' => (string) ($row['service_names'] ?? ''),
            'customer_name' => (string) ($row['customer_name_snapshot'] ?? ''),
            'total_minor' => (int) ($row['total_amount_minor'] ?? 0),
            'currency' => (string) ($row['currency'] ?? ''),
            'version' => (int) ($row['version'] ?? 1),
        ];
    }
}
