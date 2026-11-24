<?php

declare(strict_types=1);

namespace App\Modules\Staff\Controllers;

use App\Core\Database\Database;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Staff\Repositories\StaffRepository;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\AppUrl;
use App\Shared\Support\UuidFactory;
use App\Tenancy\TenantContext;

/**
 * Private JSON API for the staff directory (session-authenticated, D-008).
 * View endpoints require staff.view; mutations require staff.manage.
 */
final class StaffApiController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly StaffRepository $staff,
        private readonly Database $db,
        private readonly UuidFactory $uuids,
        private readonly FeatureGate $features,
        private readonly AppUrl $urls,
    ) {}

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view staff.', 403);
        }

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'branch' => (int) $request->query('branch', 0),
        ];

        $page = max(1, (int) $request->query('page', 1));
        $result = $this->staff->listForTenant($tenant->tenantId, $filters, $page, self::PER_PAGE);
        $pages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        return ApiResponse::ok([
            'rows' => array_map(fn(array $row): array => $this->row($row), $result['rows']),
            'total' => $result['total'],
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** Branches, services and members for the staff form.
     * @param array<string, string> $params
     */
    public function meta(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view staff.', 403);
        }

        $branches = $this->staff->branches($tenant->tenantId);
        if ($membership->branchIds !== null) {
            $branches = array_values(array_filter(
                $branches,
                static fn($b): bool => in_array((int) $b['id'], $membership->branchIds, true),
            ));
        }

        return ApiResponse::ok([
            'branches' => $branches,
            'services' => $this->staff->services($tenant->tenantId),
            'members' => $membership->can('staff.manage')
                ? $this->staff->members($tenant->tenantId)
                : [],
        ]);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.view')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view staff.', 403);
        }

        $member = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Staff member not found.', 404);
        }

        return ApiResponse::ok(['staff' => $this->detail($tenant->tenantId, $member)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage staff.', 403);
        }

        [$data, $branchIds, $serviceIds, $schedule, $fields] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        if (!$this->features->underLimit($tenant->tenantId, 'max_staff', $this->staff->countActive($tenant->tenantId))) {
            return ApiResponse::error('PLAN_LIMIT', 'Your plan limit for staff is reached. Upgrade to add more.', 403);
        }

        $uuid = $this->uuids->generate();
        $this->db->transaction(function () use ($tenant, $data, $branchIds, $serviceIds, $schedule, $uuid): void {
            $id = $this->staff->insert($data + [
                'uuid' => $uuid,
                'tenant_id' => $tenant->tenantId,
            ]);
            $this->staff->syncBranches($tenant->tenantId, $id, $branchIds);
            $this->staff->syncServices($tenant->tenantId, $id, $serviceIds);
            $this->staff->syncSchedules($tenant->tenantId, $id, $branchIds, $schedule);
        });

        $created = $this->staff->findByUuid($tenant->tenantId, $uuid);

        return ApiResponse::ok(['staff' => $created !== null ? $this->detail($tenant->tenantId, $created) : null], 201);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage staff.', 403);
        }

        $member = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Staff member not found.', 404);
        }

        [$data, $branchIds, $serviceIds, $schedule, $fields] = $this->validated($request->body(), $tenant, $membership);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->db->transaction(function () use ($tenant, $member, $data, $branchIds, $serviceIds, $schedule): void {
            $this->staff->update($tenant->tenantId, (int) $member['id'], $data);
            $this->staff->syncBranches($tenant->tenantId, (int) $member['id'], $branchIds);
            $this->staff->syncServices($tenant->tenantId, (int) $member['id'], $serviceIds);
            $this->staff->syncSchedules($tenant->tenantId, (int) $member['id'], $branchIds, $schedule);
        });

        $updated = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);

        return ApiResponse::ok(['staff' => $updated !== null ? $this->detail($tenant->tenantId, $updated) : null]);
    }

    /** @param array<string, string> $params */
    public function destroy(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage staff.', 403);
        }

        $member = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Staff member not found.', 404);
        }

        if ($this->staff->referenceCount($tenant->tenantId, (int) $member['id']) > 0) {
            return ApiResponse::error(
                'STAFF_IN_USE',
                'This staff member has bookings or customer links. Mark them inactive instead.',
                409,
            );
        }

        $this->staff->delete($tenant->tenantId, (int) $member['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * Mint (or rotate) the member's public .ics feed token and return the
     * subscription URL. Only the SHA-256 is stored; the raw token leaves in
     * this response only — like booking manage tokens.
     * @param array<string, string> $params
     */
    public function calendarFeed(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage staff.', 403);
        }

        $member = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Staff member not found.', 404);
        }

        $token = bin2hex(random_bytes(32));
        $this->staff->setCalendarTokenHash($tenant->tenantId, (int) $member['id'], hash('sha256', $token));

        return ApiResponse::ok(['url' => $this->urls->to(HostGroup::Api, '/v1/staff-calendar/' . $token . '.ics', $request)]);
    }

    /** @param array<string, string> $params */
    public function calendarFeedRevoke(Request $request, array $params): Response
    {
        [$tenant, $membership] = $this->context($request);
        if (!$membership->can('staff.manage')) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to manage staff.', 403);
        }

        $member = $this->staff->findByUuid($tenant->tenantId, (string) $params['uuid']);
        if ($member === null) {
            return ApiResponse::error('NOT_FOUND', 'Staff member not found.', 404);
        }

        $this->staff->setCalendarTokenHash($tenant->tenantId, (int) $member['id'], null);

        return ApiResponse::ok(['revoked' => true]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: array<string, mixed>, 1: list<int>, 2: list<int>,
     *               3: list<array{weekday: int, starts_at: string, ends_at: string}>,
     *               4: array<string, string>}
     */
    private function validated(array $body, TenantContext $tenant, MembershipContext $membership): array
    {
        $fields = [];

        $first = trim((string) ($body['first_name'] ?? ''));
        $last = trim((string) ($body['last_name'] ?? ''));
        $display = trim((string) ($body['display_name'] ?? ''));
        if ($first === '' || mb_strlen($first) > 100) {
            $fields['first_name'] = 'First name is required (max 100 characters).';
        }
        if ($last === '' || mb_strlen($last) > 100) {
            $fields['last_name'] = 'Last name is required (max 100 characters).';
        }
        if ($display === '' || mb_strlen($display) > 150) {
            $fields['display_name'] = 'Display name is required (max 150 characters).';
        }

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $fields['email'] = 'Enter a valid email address.';
        }
        $phone = trim((string) ($body['phone_e164'] ?? ''));
        if ($phone !== '' && preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
            $fields['phone_e164'] = 'Use international format, e.g. +254712345678.';
        }

        $memberLink = (int) ($body['tenant_user_id'] ?? 0);
        if ($memberLink > 0 && !$this->staff->membershipValid($tenant->tenantId, $memberLink)) {
            $fields['tenant_user_id'] = 'Choose a valid team member.';
        }

        $branchIds = array_values(array_unique(array_map('intval', (array) ($body['branch_ids'] ?? []))));
        $allowed = array_map(static fn($b): int => (int) $b['id'], $this->staff->branches($tenant->tenantId));
        if ($membership->branchIds !== null) {
            $allowed = array_values(array_intersect($allowed, $membership->branchIds));
        }
        if ($branchIds === [] || array_diff($branchIds, $allowed) !== []) {
            $fields['branch_ids'] = 'Assign the staff member to at least one branch you manage.';
        }

        $serviceIds = array_values(array_unique(array_map('intval', (array) ($body['service_ids'] ?? []))));
        $validServices = array_map(static fn($s): int => (int) $s['id'], $this->staff->services($tenant->tenantId));
        if (array_diff($serviceIds, $validServices) !== []) {
            $fields['service_ids'] = 'Choose valid services.';
        }

        $schedule = [];
        foreach ((array) ($body['schedule'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weekday = (int) ($row['weekday'] ?? 0);
            $start = trim((string) ($row['starts_at'] ?? ''));
            $end = trim((string) ($row['ends_at'] ?? ''));
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }
            if (preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1 || $start >= $end) {
                $fields['schedule'] = 'Every working day needs a valid start and end time.';
                continue;
            }
            $schedule[] = ['weekday' => $weekday, 'starts_at' => $start . ':00', 'ends_at' => $end . ':00'];
        }

        $status = (string) ($body['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $data = [
            'tenant_user_id' => $memberLink > 0 ? $memberLink : null,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $display,
            'email' => $email !== '' ? $email : null,
            'phone_e164' => $phone !== '' ? $phone : null,
            'bio' => trim((string) ($body['bio'] ?? '')) ?: null,
            'commission_pct' => $body['commission_pct'] !== null && $body['commission_pct'] !== ''
                ? min(100, max(0, (float) $body['commission_pct'])) : null,
            'booking_priority' => max(0, (int) ($body['booking_priority'] ?? 0)),
            'is_bookable_online' => !empty($body['is_bookable_online']) ? 1 : 0,
            'status' => $status,
        ];

        return [$data, $branchIds, $serviceIds, $schedule, $fields];
    }

    /** Full detail shape for show/create/update responses.
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function detail(int $tenantId, array $member): array
    {
        $staffId = (int) $member['id'];
        $branchIds = $this->staff->branchIds($tenantId, $staffId);
        $serviceIds = $this->staff->serviceIds($tenantId, $staffId);

        return array_merge($this->row($member), [
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'bio' => $member['bio'],
            'commission_pct' => $member['commission_pct'] !== null ? (float) $member['commission_pct'] : null,
            'tenant_user_id' => $member['tenant_user_id'] !== null ? (int) $member['tenant_user_id'] : null,
            'booking_priority' => (int) $member['booking_priority'],
            'services' => count($serviceIds),
            'branch_ids' => $branchIds,
            'service_ids' => $serviceIds,
            'schedule' => $this->scheduleRows($member),
            'references' => $this->staff->referenceCount($tenantId, $staffId),
            'has_calendar_feed' => $this->staff->hasCalendarToken($tenantId, $staffId),
        ]);
    }

    /**
     * Collapse per-branch schedule rows into one weekday map (this module
     * writes identical rows across branches, so first row per weekday wins).
     *
     * @param array<string, mixed> $member
     * @return list<array{weekday: int, starts_at: string, ends_at: string}>
     */
    private function scheduleRows(array $member): array
    {
        $seen = [];
        foreach ($this->staff->schedules((int) $member['tenant_id'], (int) $member['id']) as $row) {
            $weekday = (int) $row['weekday'];
            if (!isset($seen[$weekday])) {
                $seen[$weekday] = [
                    'weekday' => $weekday,
                    'starts_at' => substr((string) $row['starts_at'], 0, 5),
                    'ends_at' => substr((string) $row['ends_at'], 0, 5),
                ];
            }
        }

        return array_values($seen);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'display_name' => $row['display_name'],
            'email' => $row['email'],
            'phone' => $row['phone_e164'],
            'services' => (int) ($row['service_count'] ?? 0),
            'branches' => $row['branch_names'] ?? null,
            'is_bookable_online' => (bool) $row['is_bookable_online'],
            'status' => $row['status'],
        ];
    }

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
}
