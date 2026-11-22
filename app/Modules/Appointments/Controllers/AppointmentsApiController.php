<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Auth\Context\MembershipContext;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Time\DateTimeService;
use App\Tenancy\TenantContext;

/**
 * JSON API behind the appointments list, calendar grid and details drawer.
 * All queries are tenant-scoped; branch scope comes from the membership
 * (never from the request body). UUIDs only — never auto-increment ids (D-008).
 */
final class AppointmentsApiController
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentTransitionService $transitions,
        private readonly ManualBookingService $bookings,
        private readonly DateTimeService $dates,
        private readonly CurrencyFormatter $currency,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'service' => (string) $request->query('service', ''),
            'staff' => (string) $request->query('staff', ''),
            'branch' => (string) $request->query('branch', ''),
            'date' => (string) $request->query('date', ''),
            'from' => $this->dateToUtcStart((string) $request->query('from', ''), $tenant),
            'to' => $this->dateToUtcEnd((string) $request->query('to', ''), $tenant),
        ];
        $filters = array_filter($filters, static fn($v) => $v !== '' && $v !== null);

        if (!$membership->can('appointments.view')) {
            if ($membership->can('appointments.view_own')) {
                $own = $this->appointments->ownStaffId($tenant->tenantId, $membership->tenantUserId);
                $filters['staff'] = $own !== null ? (string) $own : '-1';
            } else {
                return ApiResponse::error('FORBIDDEN', 'You do not have permission to view appointments.', 403);
            }
        }

        $page = max(1, (int) $request->query('page', 1));
        $result = $this->appointments->listForTenant($tenant->tenantId, $membership->branchIds, $filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        $today = $this->dates->localToday($tenant->timezone);
        [$from, $to] = $this->dates->localDayToUtcRange($today, $tenant->timezone);
        $stats = $this->appointments->dayStats($tenant->tenantId, $membership->branchIds, $this->dates->toDb($from), $this->dates->toDb($to));

        return ApiResponse::ok([
            'rows' => array_map(fn($row) => $this->decorate($row, $tenant), $result['rows']),
            'page' => $page,
            'pages' => $pages,
            'total' => $result['total'],
            'stats' => $stats,
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        $uuid = (string) ($params['uuid'] ?? '');
        $row = $this->appointments->findByUuid($tenant->tenantId, $uuid);
        if ($row === null || !$membership->canAccessBranch((int) $row['branch_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Appointment not found.', 404);
        }
        $this->assertOwnScope($membership, $tenant, $row);

        $items = $this->appointments->itemsFor($tenant->tenantId, (int) $row['id']);
        $events = $this->appointments->eventsFor($tenant->tenantId, (int) $row['id']);
        $resourceNames = $this->appointments->resourceNamesForAppointment($tenant->tenantId, (int) $row['id']);

        $allowed = array_values(array_filter(
            $this->transitions->allowed((string) $row['status']),
            fn(string $to) => ($perm = $this->transitions->permissionFor($to)) === null || $membership->can($perm),
        ));

        // Visit transitions are time-gated — tell the UI which actions are
        // outside their window so buttons disable with a reason instead of
        // failing on submit.
        $prefs = $this->appointments->visitWindowPrefs($tenant->tenantId);
        $transitionStates = [];
        foreach ($allowed as $to) {
            $issue = $this->transitions->timingError($to, (string) $row['start_at'], (string) $row['end_at'], $prefs);
            $transitionStates[$to] = [
                'ok' => $issue === null,
                'code' => $issue[0] ?? null,
                'reason' => $issue[1] ?? null,
            ];
        }

        $branchTz = $row['branch_timezone'] ?: $tenant->timezone;

        return ApiResponse::ok([
            'uuid' => $row['uuid'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'payment_status' => $row['payment_status'],
            'version' => (int) $row['version'],
            'branch' => $row['branch_name'],
            'date_label' => $this->localDate($row['start_at'], $branchTz),
            'time_label' => $this->localTime($row['start_at'], $branchTz) . ' – ' . $this->localTime($row['end_at'], $branchTz),
            'customer' => [
                'name' => $row['customer_name_snapshot'],
                'phone' => $row['customer_phone_snapshot'],
                'email' => $row['customer_email_snapshot'],
            ],
            'items' => array_map(fn(array $item) => [
                'service' => $item['service_name_snapshot'],
                'variant' => $item['variant_name_snapshot'],
                'staff' => $item['staff_name'],
                'resources' => $resourceNames[(int) $item['id']] ?? null,
                'duration_minutes' => (int) $item['duration_minutes'],
                'price_label' => $item['unit_amount_minor'] !== null ? $this->money((int) $item['unit_amount_minor'] * (int) $item['quantity'], (string) $item['currency']) : null,
            ], $items),
            'total_label' => $row['total_amount_minor'] !== null ? $this->money((int) $row['total_amount_minor'], (string) $row['currency']) : null,
            'notes' => $row['notes'] ?? null,
            'allowed_transitions' => $allowed,
            'transition_states' => $transitionStates,
            'history' => array_map(fn(array $event) => [
                'label' => $this->eventLabel($event),
                'at' => (string) $event['created_at'],
            ], $events),
        ]);
    }

    /** @param array<string, string> $params */
    public function transition(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        $uuid = (string) ($params['uuid'] ?? '');
        $body = $request->body();
        $to = (string) ($body['to'] ?? '');
        $version = isset($body['version']) ? (int) $body['version'] : null;
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;

        $permission = $this->transitions->permissionFor($to);
        if ($permission === null) {
            return ApiResponse::error('INVALID_TRANSITION', 'Unknown transition target.', 400);
        }
        if (!$membership->can($permission)) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to do that.', 403);
        }

        try {
            $row = $this->transitions->transition($tenant->tenantId, $uuid, $to, $version, $membership->actorUserId());
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok([
            'uuid' => $row['uuid'],
            'status' => $row['status'],
            'version' => (int) $row['version'],
        ]);
    }

    /** @param array<string, string> $params */
    public function events(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        $from = $this->dateToUtcStart((string) $request->query('from', ''), $tenant);
        $to = $this->dateToUtcEnd((string) $request->query('to', ''), $tenant);
        if ($from === null || $to === null) {
            return ApiResponse::error('VALIDATION', 'from and to must be Y-m-d dates.', 422, ['from' => 'Invalid range.']);
        }
        $branchId = $this->intOrNull($request->query('branch'));
        $staffId = $this->intOrNull($request->query('staff'));
        if ($branchId !== null && !$membership->canAccessBranch($branchId)) {
            return ApiResponse::error('FORBIDDEN', 'Branch out of scope.', 403);
        }
        if (!$membership->can('appointments.view') && $membership->can('appointments.view_own')) {
            $staffId = $this->appointments->ownStaffId($tenant->tenantId, $membership->tenantUserId);
            if ($staffId === null) {
                return ApiResponse::ok(['events' => []]);
            }
        }

        $branchIds = $branchId !== null ? [$branchId] : $membership->branchIds;
        $rows = $this->appointments->forRange($tenant->tenantId, $branchIds, $from, $to, $staffId);

        return ApiResponse::ok([
            'events' => array_map(fn($row) => $this->decorate($row, $tenant, true), $rows),
        ]);
    }

    /** @param array<string, string> $params */
    public function filters(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        $branches = $this->appointments->branchesFor($tenant->tenantId, $membership->branchIds);
        $staffLinks = $this->appointments->staffLinkMaps($tenant->tenantId);
        $serviceLinks = $this->appointments->serviceLinkMaps($tenant->tenantId);

        return ApiResponse::ok([
            'branches' => array_map(fn($b) => ['id' => (int) $b['id'], 'name' => $b['name']], $branches),
            'staff' => array_map(fn($s) => [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'branch_ids' => $staffLinks['branch_ids'][(int) $s['id']] ?? [],
                'service_ids' => $staffLinks['service_ids'][(int) $s['id']] ?? [],
            ], $this->appointments->staffFor($tenant->tenantId)),
            'services' => array_map(fn($s) => [
                'id' => (int) $s['id'],
                'name' => $s['name'],
                'duration_minutes' => (int) $s['duration_minutes'],
                'branch_ids' => $serviceLinks['branch_ids'][(int) $s['id']] ?? [],
                'required_resource_type_id' => $serviceLinks['resource_type'][(int) $s['id']] ?? null,
            ], $this->appointments->servicesFor($tenant->tenantId)),
            'resources' => array_map(fn($r) => [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'branch_id' => (int) $r['branch_id'],
                'resource_type_id' => (int) $r['resource_type_id'],
            ], $this->appointments->resourcesFor($tenant->tenantId, $membership->branchIds)),
        ]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('appointments.create')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to create appointments.', 403);
        }

        $body = $request->body();
        $branchId = (int) ($body['branch_id'] ?? 0);
        $staffId = (int) ($body['staff_id'] ?? 0);
        $serviceId = (int) ($body['service_id'] ?? 0);
        $date = (string) ($body['date'] ?? '');
        $time = (string) ($body['time'] ?? '');
        $name = trim((string) ($body['name'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $notes = trim((string) ($body['notes'] ?? ''));
        $resourceId = (int) ($body['resource_id'] ?? 0);
        $walkIn = !empty($body['walk_in']);

        $fields = [];
        if ($branchId <= 0) $fields['branch_id'] = 'Choose a branch.';
        elseif (!$membership->canAccessBranch($branchId)) $fields['branch_id'] = 'Branch out of scope.';
        if ($staffId <= 0) $fields['staff_id'] = 'Choose a staff member.';
        if ($serviceId <= 0) $fields['service_id'] = 'Choose a service.';
        if ($resourceId < 0) $fields['resource_id'] = 'Choose a valid room.';
        if (!$walkIn) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $fields['date'] = 'Pick a valid date.';
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) $fields['time'] = 'Pick a valid time.';
        }
        if ($name === '' || mb_strlen($name) > 255) $fields['name'] = 'Customer name is required.';
        if ($phone !== '' && !preg_match('/^\+[1-9]\d{6,14}$/', $phone)) $fields['phone'] = 'Use E.164 format, e.g. +254712345678.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $fields['email'] = 'Invalid email address.';
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Please fix the highlighted fields.', 422, $fields);
        }

        $branch = $this->findBranch($tenant, $branchId);
        if ($branch === null) {
            return ApiResponse::error('VALIDATION', 'Unknown branch.', 422, ['branch_id' => 'Choose a branch.']);
        }

        try {
            $created = $this->bookings->create(
                $tenant->tenantId,
                $branchId,
                (string) ($branch['timezone'] ?: $tenant->timezone),
                $staffId,
                $serviceId,
                $date,
                $time,
                ['name' => $name, 'phone' => $phone !== '' ? $phone : null, 'email' => $email !== '' ? $email : null, 'notes' => $notes !== '' ? $notes : null],
                $walkIn ? 'walk_in' : 'dashboard',
                'en',
                $tenant->currency,
                $membership->actorUserId(),
                'tenant_user',
                $membership->can('appointments.override_policy'),
                $resourceId > 0 ? $resourceId : null,
            );
        } catch (TransitionException $e) {
            return ApiResponse::error($e->apiCode, $e->getMessage(), $e->status, $e->fields ?: null);
        }

        return ApiResponse::ok($created, 201);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0: TenantContext, 1: MembershipContext} */
    private function context(Request $request): array
    {
        $tenant = $request->attribute('tenant');
        $membership = $request->attribute('membership');
        if (!$tenant instanceof TenantContext || !$membership instanceof MembershipContext) {
            throw new \LogicException('Tenant context required.');
        }

        return [$tenant, $membership];
    }

    /** @param array<string, mixed> $row */
    private function assertOwnScope(MembershipContext $membership, TenantContext $tenant, array $row): void
    {
        if ($membership->can('appointments.view')) {
            return;
        }
        if ($membership->can('appointments.view_own')) {
            $own = $this->appointments->ownStaffId($tenant->tenantId, $membership->tenantUserId);
            if ($own !== null && (int) $row['staff_id'] === $own) {
                return;
            }
        }
        throw new \App\Core\Http\HttpException(403, 'FORBIDDEN', 'You do not have permission to view this appointment.');
    }

    /** @return array<string, mixed>|null */
    private function findBranch(TenantContext $tenant, int $branchId): ?array
    {
        foreach ($this->appointments->branchesFor($tenant->tenantId, null) as $branch) {
            if ((int) $branch['id'] === $branchId) {
                return $branch;
            }
        }

        return null;
    }

    private function dateToUtcStart(string $date, TenantContext $tenant): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        [$start] = $this->dates->localDayToUtcRange($date, $tenant->timezone);

        return $this->dates->toDb($start);
    }

    private function dateToUtcEnd(string $date, TenantContext $tenant): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        [, $end] = $this->dates->localDayToUtcRange($date, $tenant->timezone);

        return $this->dates->toDb($end);
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function localDate(string $utc, string $tz): string
    {
        return $this->dates->toLocal($this->dates->fromDb($utc), $tz)->format('D, M j, Y');
    }

    private function localTime(string $utc, string $tz): string
    {
        return $this->dates->toLocal($this->dates->fromDb($utc), $tz)->format('g:i A');
    }

    private function localIso(string $utc, string $tz): string
    {
        return $this->dates->toLocal($this->dates->fromDb($utc), $tz)->format('Y-m-d');
    }

    private function localClock(string $utc, string $tz): string
    {
        return $this->dates->toLocal($this->dates->fromDb($utc), $tz)->format('H:i');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorate(array $row, TenantContext $tenant, bool $calendar = false): array
    {
        $tz = $row['branch_timezone'] ?: $tenant->timezone;
        $out = [
            'uuid' => $row['uuid'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'payment_status' => $row['payment_status'],
            'customer' => $row['customer_name_snapshot'],
            'phone' => $row['customer_phone_snapshot'],
            'service' => $row['service_names'] ?? '',
            'staff' => $row['staff_name'],
            'branch' => $row['branch_name'],
            'date' => $this->localIso((string) $row['start_at'], $tz),
            'date_label' => $this->localDate((string) $row['start_at'], $tz),
            'time_label' => $this->localTime((string) $row['start_at'], $tz),
            'start_time' => $this->localClock((string) $row['start_at'], $tz),
            'end_time' => $this->localClock((string) $row['end_at'], $tz),
            'duration_minutes' => $row['first_duration'] !== null ? (int) $row['first_duration'] : null,
        ];
        if (!$calendar) {
            $out['total_label'] = $row['total_amount_minor'] !== null ? $this->money((int) $row['total_amount_minor'], (string) $row['currency']) : null;
        }

        return $out;
    }

    private function money(int $minor, string $currency): string
    {
        return $this->currency->format(new Money($minor, $currency));
    }

    /** @param array<string, mixed> $event */
    private function eventLabel(array $event): string
    {
        $type = (string) $event['event_type'];
        if ($type === 'status.created') {
            return 'Created';
        }
        if (str_starts_with($type, 'status.')) {
            return 'Status → ' . str_replace('_', ' ', substr($type, 7));
        }

        return str_replace('_', ' ', $type);
    }
}
