<?php

declare(strict_types=1);

namespace App\Modules\Availability\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Availability\Repositories\AvailabilityRepository;
use App\Shared\Http\ApiResponse;
use App\Tenancy\TenantContext;

/**
 * Breaks, time off, closures and blocked time (update.md 3.2).
 *
 * Permissions:
 *  - staff.manage           weekly breaks; add/approve/decline anyone's time off
 *  - own staff profile      request your own time off (lands as "requested")
 *  - branches.manage        closures, public holidays and special hours
 *  - calendar.manage_blocks block time for a staff member or a whole branch
 * Everything is limited to the member's branch scope.
 *
 * Staff times are entered in the business timezone; branch closures and
 * branch blocks in that branch's timezone. Storage is UTC (engine contract).
 */
final class AvailabilityApiController
{
    private const MAX_LEAVE_DAYS = 366;
    private const MAX_BLOCK_DAYS = 31;

    public function __construct(private readonly AvailabilityRepository $repo) {}

    /** @param array<string, string> $params */
    public function overview(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $staff = $this->repo->staff($tenant->tenantId, $m->branchIds);
        $own = $this->ownStaffId($staff, $m);
        if (!$this->canView($m, $own)) {
            return ApiResponse::error('FORBIDDEN', 'You do not have permission to view schedules.', 403);
        }
        $visibleStaff = $m->can('staff.view') || $m->can('staff.manage') || $m->can('calendar.manage_blocks')
            ? $staff
            : array_values(array_filter($staff, static fn(array $s): bool => $s['id'] === $own));
        $staffIds = array_column($visibleStaff, 'id');
        $branches = $this->repo->branches($tenant->tenantId, $m->branchIds, $tenant->timezone);
        $branchIds = array_column($branches, 'id');
        $tz = $this->tz($tenant->timezone);
        $tzByBranch = array_column($branches, 'timezone', 'id');

        return ApiResponse::ok([
            'timezone' => $tenant->timezone,
            'staff' => $visibleStaff,
            'branches' => $branches,
            'leave_types' => $this->repo->leaveTypes($tenant->tenantId),
            'own_staff_id' => $own,
            'can' => [
                'manage_staff' => $m->can('staff.manage'),
                'manage_branches' => $m->can('branches.manage'),
                'block_time' => $m->can('calendar.manage_blocks'),
            ],
            'breaks' => array_map(static fn(array $b): array => [
                'id' => (int) $b['id'],
                'staff_id' => (int) $b['staff_id'],
                'staff_name' => (string) $b['staff_name'],
                'weekday' => (int) $b['weekday'],
                'starts_at' => (string) $b['starts_at'],
                'ends_at' => (string) $b['ends_at'],
            ], $this->repo->breaks($tenant->tenantId, $staffIds)),
            'leave' => array_map(fn(array $l): array => [
                'id' => (int) $l['id'],
                'staff_id' => (int) $l['staff_id'],
                'staff_name' => (string) $l['staff_name'],
                'type' => (string) $l['type_name'],
                'status' => (string) $l['status'],
                'reason' => (string) ($l['reason'] ?? ''),
                'label' => $this->rangeLabel((string) $l['starts_at'], (string) $l['ends_at'], $tz),
            ], $this->repo->leave($tenant->tenantId, $staffIds)),
            'closures' => array_map(static fn(array $c): array => [
                'id' => (int) $c['id'],
                'branch_id' => (int) $c['branch_id'],
                'branch_name' => (string) $c['branch_name'],
                'date' => (string) $c['local_date'],
                'closed' => (bool) $c['is_closed'],
                'opens_at' => $c['opens_at'],
                'closes_at' => $c['closes_at'],
                'reason' => (string) ($c['reason'] ?? ''),
            ], $this->repo->closures($tenant->tenantId, $branchIds)),
            'blocks' => array_map(fn(array $p): array => [
                'id' => (int) $p['id'],
                'target' => $p['staff_id'] !== null ? (string) $p['staff_name'] : (string) $p['branch_name'] . ' (whole branch)',
                'reason' => (string) ($p['reason'] ?? ''),
                'label' => $this->rangeLabel(
                    (string) $p['starts_at'],
                    (string) $p['ends_at'],
                    $p['branch_id'] !== null ? $this->tz((string) ($tzByBranch[(int) $p['branch_id']] ?? $tenant->timezone)) : $tz,
                ),
            ], $this->repo->blocks($tenant->tenantId, $staffIds, $branchIds)),
        ]);
    }

    /* ------------------------------------------------------------- breaks */

    /** @param array<string, string> $params */
    public function addBreak(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        if (!$m->can('staff.manage')) {
            return $this->forbidden();
        }
        $body = $request->body();
        $staffId = (int) ($body['staff_id'] ?? 0);
        $weekdays = array_values(array_unique(array_map('intval', is_array($body['weekdays'] ?? null) ? $body['weekdays'] : [])));
        $start = $this->time($body['starts_at'] ?? null);
        $end = $this->time($body['ends_at'] ?? null);

        $fields = [];
        if (!$this->staffInScope($tenant, $m, $staffId)) {
            $fields['staff_id'] = 'Choose a team member.';
        }
        if ($weekdays === [] || array_diff($weekdays, range(1, 7)) !== []) {
            $fields['weekdays'] = 'Choose at least one day.';
        }
        if ($start === null) {
            $fields['starts_at'] = 'Enter a start time.';
        }
        if ($end === null) {
            $fields['ends_at'] = 'Enter an end time.';
        } elseif ($start !== null && $end <= $start) {
            $fields['ends_at'] = 'The break must end after it starts.';
        }
        if ($fields === []) {
            foreach ($weekdays as $day) {
                if ($this->repo->breakOverlaps($tenant->tenantId, $staffId, $day, (string) $start, (string) $end)) {
                    $fields['starts_at'] = 'This overlaps an existing break on ' . self::dayName($day) . '.';
                    break;
                }
            }
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }
        foreach ($weekdays as $day) {
            $this->repo->addBreak($tenant->tenantId, $staffId, $day, (string) $start, (string) $end);
        }

        return ApiResponse::ok(['added' => count($weekdays)], 201);
    }

    /** @param array<string, string> $params */
    public function deleteBreak(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $row = $this->repo->findBreak($tenant->tenantId, (int) ($params['id'] ?? 0));
        if ($row === null || !$m->can('staff.manage') || !$this->staffInScope($tenant, $m, (int) $row['staff_id'])) {
            return ApiResponse::error('NOT_FOUND', 'Break not found.', 404);
        }
        $this->repo->deleteBreak($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /* -------------------------------------------------------------- leave */

    /** @param array<string, string> $params */
    public function addLeave(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $body = $request->body();
        $staffId = (int) ($body['staff_id'] ?? 0);
        $staff = $this->repo->staff($tenant->tenantId, $m->branchIds);
        $own = $this->ownStaffId($staff, $m);
        $manager = $m->can('staff.manage') && in_array($staffId, array_column($staff, 'id'), true);
        if (!$manager && $staffId !== $own) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, ['staff_id' => 'You can only request time off for yourself.']);
        }

        $fields = [];
        $typeId = (int) ($body['leave_type_id'] ?? 0);
        if (!$this->repo->leaveTypeExists($tenant->tenantId, $typeId)) {
            $fields['leave_type_id'] = 'Choose a type.';
        }
        [$startUtc, $endUtc, $windowErrors] = $this->window($body, $this->tz($tenant->timezone), self::MAX_LEAVE_DAYS);
        $fields += $windowErrors;
        $reason = $this->reason($body);
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        // Managers record approved time off; staff requesting their own
        // time off wait for a manager's approval.
        $status = $manager ? 'approved' : 'requested';
        $this->repo->addLeave($tenant->tenantId, $staffId, $typeId, (string) $startUtc, (string) $endUtc, $status, $reason, $m->userId);

        return ApiResponse::ok([
            'status' => $status,
            'overlapping_appointments' => $this->repo->overlappingAppointments($tenant->tenantId, $staffId, null, (string) $startUtc, (string) $endUtc),
        ], 201);
    }

    /** approve | decline (rejected) | cancel.
     * @param array<string, string> $params
     */
    public function leaveStatus(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $row = $this->repo->findLeave($tenant->tenantId, (int) ($params['id'] ?? 0));
        $staff = $this->repo->staff($tenant->tenantId, $m->branchIds);
        if ($row === null || !in_array((int) $row['staff_id'], array_column($staff, 'id'), true)) {
            return ApiResponse::error('NOT_FOUND', 'Time off not found.', 404);
        }
        $to = (string) ($request->body()['status'] ?? '');
        $own = (int) $row['staff_id'] === $this->ownStaffId($staff, $m);
        $from = (string) $row['status'];

        $allowed = match ($to) {
            'approved', 'rejected' => $m->can('staff.manage') && $from === 'requested',
            // Staff may withdraw their own request; managers may cancel any.
            'cancelled' => in_array($from, ['requested', 'approved'], true)
                && ($m->can('staff.manage') || ($own && $from === 'requested')),
            default => false,
        };
        if (!$allowed) {
            return ApiResponse::error('INVALID_TRANSITION', 'That change is not allowed.', 409);
        }
        $this->repo->setLeaveStatus($tenant->tenantId, (int) $row['id'], $to, $m->userId);

        return ApiResponse::ok(['status' => $to]);
    }

    /* ---------------------------------------------------------- closures */

    /** @param array<string, string> $params */
    public function saveClosure(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        if (!$m->can('branches.manage')) {
            return $this->forbidden();
        }
        $body = $request->body();
        $branchIds = array_values(array_unique(array_map('intval', is_array($body['branch_ids'] ?? null) ? $body['branch_ids'] : [])));
        $scope = array_column($this->repo->branches($tenant->tenantId, $m->branchIds, $tenant->timezone), 'id');
        $date = $this->isoDate($body['date'] ?? null);
        $toDate = $this->isoDate($body['to_date'] ?? null) ?? $date;
        $closed = !empty($body['closed']);
        $opens = $closed ? null : $this->time($body['opens_at'] ?? null);
        $closes = $closed ? null : $this->time($body['closes_at'] ?? null);

        $fields = [];
        if ($branchIds === [] || array_diff($branchIds, $scope) !== []) {
            $fields['branch_ids'] = 'Choose at least one branch.';
        }
        if ($date === null) {
            $fields['date'] = 'Choose a date.';
        } elseif ($toDate === null || $toDate < $date) {
            $fields['to_date'] = 'The last day must be on or after the first day.';
        } elseif ((new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($toDate))->days > 60) {
            $fields['to_date'] = 'Add at most 60 days at a time.';
        }
        if (!$closed && ($opens === null || $closes === null || $closes <= $opens)) {
            $fields['opens_at'] = 'Enter opening and closing times (closing after opening).';
        }
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $reason = $this->reason($body);
        $days = 0;
        for ($d = new \DateTimeImmutable((string) $date); $d->format('Y-m-d') <= $toDate; $d = $d->modify('+1 day')) {
            foreach ($branchIds as $branchId) {
                $this->repo->saveClosure($tenant->tenantId, $branchId, $d->format('Y-m-d'), $closed, $opens, $closes, $reason, $m->userId);
            }
            $days++;
        }

        return ApiResponse::ok(['days' => $days], 201);
    }

    /** @param array<string, string> $params */
    public function deleteClosure(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $row = $this->repo->findClosure($tenant->tenantId, (int) ($params['id'] ?? 0));
        $scope = array_column($this->repo->branches($tenant->tenantId, $m->branchIds, $tenant->timezone), 'id');
        if ($row === null || $row['branch_id'] === null || !$m->can('branches.manage') || !in_array((int) $row['branch_id'], $scope, true)) {
            return ApiResponse::error('NOT_FOUND', 'Closure not found.', 404);
        }
        $this->repo->deleteClosure($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /* ------------------------------------------------------- blocked time */

    /** @param array<string, string> $params */
    public function addBlock(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        if (!$m->can('calendar.manage_blocks')) {
            return $this->forbidden();
        }
        $body = $request->body();
        $target = (string) ($body['target'] ?? 'staff');
        $staffId = $target === 'staff' ? (int) ($body['staff_id'] ?? 0) : null;
        $branchId = $target === 'branch' ? (int) ($body['branch_id'] ?? 0) : null;

        $fields = [];
        $tz = $this->tz($tenant->timezone);
        if ($staffId !== null) {
            if (!$this->staffInScope($tenant, $m, $staffId)) {
                $fields['staff_id'] = 'Choose a team member.';
            }
        } elseif ($branchId !== null) {
            $branches = array_column($this->repo->branches($tenant->tenantId, $m->branchIds, $tenant->timezone), 'timezone', 'id');
            if (!isset($branches[$branchId])) {
                $fields['branch_id'] = 'Choose a branch.';
            } else {
                $tz = $this->tz((string) $branches[$branchId]);
            }
        } else {
            $fields['target'] = 'Choose who to block.';
        }
        [$startUtc, $endUtc, $windowErrors] = $this->window($body, $tz, self::MAX_BLOCK_DAYS);
        $fields += $windowErrors;
        if ($fields !== []) {
            return ApiResponse::error('VALIDATION', 'Fix the highlighted fields.', 422, $fields);
        }

        $this->repo->addBlock($tenant->tenantId, $staffId, $branchId, (string) $startUtc, (string) $endUtc, $this->reason($body), $m->userId);

        return ApiResponse::ok([
            'overlapping_appointments' => $this->repo->overlappingAppointments($tenant->tenantId, $staffId, $branchId, (string) $startUtc, (string) $endUtc),
        ], 201);
    }

    /** @param array<string, string> $params */
    public function deleteBlock(Request $request, array $params): Response
    {
        [$tenant, $m] = $this->context($request);
        $row = $this->repo->findBlock($tenant->tenantId, (int) ($params['id'] ?? 0));
        $ok = $row !== null && $m->can('calendar.manage_blocks') && $row['resource_id'] === null;
        if ($ok && $row['staff_id'] !== null) {
            $ok = $this->staffInScope($tenant, $m, (int) $row['staff_id']);
        } elseif ($ok) {
            $ok = in_array((int) $row['branch_id'], array_column($this->repo->branches($tenant->tenantId, $m->branchIds, $tenant->timezone), 'id'), true);
        }
        if (!$ok) {
            return ApiResponse::error('NOT_FOUND', 'Blocked time not found.', 404);
        }
        $this->repo->deleteBlock($tenant->tenantId, (int) $row['id']);

        return ApiResponse::ok(['deleted' => true]);
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Local window → UTC. Accepts {date, to_date?, all_day} for whole days or
     * {date, start_time, end_time} for part of one day.
     *
     * @param array<string, mixed> $body
     * @return array{0: ?string, 1: ?string, 2: array<string, string>}
     */
    private function window(array $body, \DateTimeZone $tz, int $maxDays): array
    {
        $date = $this->isoDate($body['date'] ?? null);
        if ($date === null) {
            return [null, null, ['date' => 'Choose a date.']];
        }
        $utc = new \DateTimeZone('UTC');
        if (!empty($body['all_day'])) {
            $toDate = $this->isoDate($body['to_date'] ?? null) ?? $date;
            if ($toDate < $date) {
                return [null, null, ['to_date' => 'The last day must be on or after the first day.']];
            }
            $start = new \DateTimeImmutable($date . ' 00:00', $tz);
            $end = (new \DateTimeImmutable($toDate . ' 00:00', $tz))->modify('+1 day');
        } else {
            $from = $this->time($body['start_time'] ?? null);
            $to = $this->time($body['end_time'] ?? null);
            if ($from === null || $to === null || $to <= $from) {
                return [null, null, ['start_time' => 'Enter a start and end time (end after start).']];
            }
            $start = new \DateTimeImmutable($date . ' ' . $from, $tz);
            $end = new \DateTimeImmutable($date . ' ' . $to, $tz);
        }
        if ($end <= new \DateTimeImmutable('now', $utc)) {
            return [null, null, ['date' => 'Choose a time that has not already passed.']];
        }
        if (($end->getTimestamp() - $start->getTimestamp()) > $maxDays * 86400) {
            return [null, null, ['to_date' => sprintf('Add at most %d days at a time.', $maxDays)]];
        }

        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'), []];
    }

    private function rangeLabel(string $startUtc, string $endUtc, \DateTimeZone $tz): string
    {
        $utc = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable($startUtc, $utc))->setTimezone($tz);
        $end = (new \DateTimeImmutable($endUtc, $utc))->setTimezone($tz);
        // Whole local days read as dates only ("Mon 1 Dec – Wed 3 Dec").
        if ($start->format('H:i') === '00:00' && $end->format('H:i') === '00:00') {
            $last = $end->modify('-1 day');

            return $start->format('D j M') . ($last->format('Y-m-d') !== $start->format('Y-m-d') ? ' – ' . $last->format('D j M') : '') . ' (all day)';
        }
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return $start->format('D j M, H:i') . '–' . $end->format('H:i');
        }

        return $start->format('D j M H:i') . ' – ' . $end->format('D j M H:i');
    }

    /** @param list<array{id: int, name: string, tenant_user_id: int|null}> $staff */
    private function ownStaffId(array $staff, MembershipContext $m): ?int
    {
        foreach ($staff as $s) {
            if ($s['tenant_user_id'] === $m->tenantUserId) {
                return $s['id'];
            }
        }

        return null;
    }

    private function canView(MembershipContext $m, ?int $own): bool
    {
        return $own !== null || $m->can('staff.view') || $m->can('staff.manage')
            || $m->can('branches.manage') || $m->can('calendar.manage_blocks');
    }

    private function staffInScope(TenantContext $tenant, MembershipContext $m, int $staffId): bool
    {
        return in_array($staffId, array_column($this->repo->staff($tenant->tenantId, $m->branchIds), 'id'), true);
    }

    private function isoDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
    }

    private function time(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }

    /** @param array<string, mixed> $body */
    private function reason(array $body): ?string
    {
        $reason = trim((string) ($body['reason'] ?? ''));

        return $reason === '' ? null : mb_substr($reason, 0, 255);
    }

    private function tz(string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    private static function dayName(int $day): string
    {
        return ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day] ?? 'that day';
    }

    private function forbidden(): Response
    {
        return ApiResponse::error('FORBIDDEN', 'You do not have permission to do that.', 403);
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
