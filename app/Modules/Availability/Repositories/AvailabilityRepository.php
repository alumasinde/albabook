<?php

declare(strict_types=1);

namespace App\Modules\Availability\Repositories;

use App\Core\Database\Database;

/**
 * Writers/readers for the schedule exceptions the availability engine
 * already honours: staff_breaks, staff_leave, schedule_overrides (branch
 * closures / special hours) and blocked_periods (blocked time).
 *
 * Every statement pins tenant_id. Callers check permissions and branch
 * scope; this class only narrows by the ids it is given.
 */
final class AvailabilityRepository
{
    /** Leave types created on first use when a business has none. */
    private const DEFAULT_LEAVE_TYPES = [
        ['annual', 'Annual leave', 1],
        ['sick', 'Sick leave', 1],
        ['personal', 'Personal / unpaid', 0],
        ['training', 'Training', 1],
    ];

    public function __construct(private readonly Database $db) {}

    /* ------------------------------------------------------------ lookups */

    /**
     * Active staff, optionally limited to those working in $branchIds.
     *
     * @param list<int>|null $branchIds null = all
     * @return list<array{id: int, name: string, tenant_user_id: int|null}>
     */
    public function staff(int $tenantId, ?array $branchIds): array
    {
        $params = ['t' => $tenantId];
        $scope = '';
        if ($branchIds !== null) {
            $scope = ' AND EXISTS (SELECT 1 FROM staff_branches sb WHERE sb.tenant_id = s.tenant_id AND sb.staff_id = s.id AND sb.branch_id IN ('
                . $this->marks($branchIds, $params) . '))';
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.display_name, s.tenant_user_id FROM staff s
              WHERE s.tenant_id = :t AND s.status = 'active'{$scope}
              ORDER BY s.display_name",
        );
        $stmt->execute($params);

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['display_name'],
            'tenant_user_id' => $r['tenant_user_id'] === null ? null : (int) $r['tenant_user_id'],
        ], $stmt->fetchAll()));
    }

    /**
     * @param list<int>|null $branchIds
     * @return list<array{id: int, name: string, timezone: string}>
     */
    public function branches(int $tenantId, ?array $branchIds, string $tenantTimezone): array
    {
        $params = ['t' => $tenantId];
        $scope = $branchIds === null ? '' : ' AND id IN (' . $this->marks($branchIds, $params) . ')';
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, name, timezone FROM branches WHERE tenant_id = :t AND is_active = 1{$scope} ORDER BY name",
        );
        $stmt->execute($params);

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'timezone' => (string) ($r['timezone'] ?: $tenantTimezone),
        ], $stmt->fetchAll()));
    }

    /** @return list<array{id: int, name: string, requires_approval: bool}> */
    public function leaveTypes(int $tenantId): array
    {
        $rows = $this->fetchLeaveTypes($tenantId);
        if ($rows === []) {
            $insert = $this->db->pdo()->prepare(
                'INSERT IGNORE INTO staff_leave_types (tenant_id, code, name, is_paid, requires_approval, sort_position)
                 VALUES (:t, :c, :n, :p, 1, :s)',
            );
            foreach (self::DEFAULT_LEAVE_TYPES as $i => [$code, $name, $paid]) {
                $insert->execute(['t' => $tenantId, 'c' => $code, 'n' => $name, 'p' => $paid, 's' => ($i + 1) * 10]);
            }
            $rows = $this->fetchLeaveTypes($tenantId);
        }

        return $rows;
    }

    /** @return list<array{id: int, name: string, requires_approval: bool}> */
    private function fetchLeaveTypes(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, requires_approval FROM staff_leave_types
              WHERE tenant_id = :t AND is_active = 1 ORDER BY sort_position, name',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values(array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'requires_approval' => (bool) $r['requires_approval'],
        ], $stmt->fetchAll()));
    }

    /* ------------------------------------------------------------- breaks */

    /**
     * @param list<int> $staffIds
     * @return list<array<string, mixed>>
     */
    public function breaks(int $tenantId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }
        $params = ['t' => $tenantId];
        $stmt = $this->db->pdo()->prepare(
            'SELECT b.id, b.staff_id, s.display_name AS staff_name, b.weekday,
                    TIME_FORMAT(b.starts_at, \'%H:%i\') AS starts_at, TIME_FORMAT(b.ends_at, \'%H:%i\') AS ends_at,
                    b.effective_from, b.effective_to
               FROM staff_breaks b
               JOIN staff s ON s.tenant_id = b.tenant_id AND s.id = b.staff_id
              WHERE b.tenant_id = :t AND b.staff_id IN (' . $this->marks($staffIds, $params) . ')
                AND (b.effective_to IS NULL OR b.effective_to >= UTC_DATE())
              ORDER BY s.display_name, b.weekday, b.starts_at',
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    public function addBreak(int $tenantId, int $staffId, int $weekday, string $start, string $end): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO staff_breaks (tenant_id, staff_id, weekday, starts_at, ends_at) VALUES (:t, :s, :w, :a, :b)',
        )->execute(['t' => $tenantId, 's' => $staffId, 'w' => $weekday, 'a' => $start, 'b' => $end]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function breakOverlaps(int $tenantId, int $staffId, int $weekday, string $start, string $end): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM staff_breaks WHERE tenant_id = :t AND staff_id = :s AND weekday = :w
               AND starts_at < :e AND ends_at > :a
               AND (effective_to IS NULL OR effective_to >= UTC_DATE()) LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId, 'w' => $weekday, 'e' => $end, 'a' => $start]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<string, mixed>|null */
    public function findBreak(int $tenantId, int $id): ?array
    {
        return $this->findIn('staff_breaks', $tenantId, $id);
    }

    public function deleteBreak(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM staff_breaks WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => $tenantId, 'id' => $id]);
    }

    /* -------------------------------------------------------------- leave */

    /**
     * Requested + approved leave that has not ended yet.
     *
     * @param list<int> $staffIds
     * @return list<array<string, mixed>>
     */
    public function leave(int $tenantId, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }
        $params = ['t' => $tenantId];
        $stmt = $this->db->pdo()->prepare(
            'SELECT l.id, l.staff_id, s.display_name AS staff_name, l.starts_at, l.ends_at, l.status, l.reason,
                    lt.name AS type_name
               FROM staff_leave l
               JOIN staff s ON s.tenant_id = l.tenant_id AND s.id = l.staff_id
               JOIN staff_leave_types lt ON lt.tenant_id = l.tenant_id AND lt.id = l.leave_type_id
              WHERE l.tenant_id = :t AND l.staff_id IN (' . $this->marks($staffIds, $params) . ")
                AND l.status IN ('requested', 'approved') AND l.ends_at > UTC_TIMESTAMP()
              ORDER BY l.status = 'requested' DESC, l.starts_at
              LIMIT 200",
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    public function addLeave(int $tenantId, int $staffId, int $typeId, string $startUtc, string $endUtc, string $status, ?string $reason, int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO staff_leave (tenant_id, staff_id, starts_at, ends_at, leave_type_id, status, reason, requested_by_user_id, approved_by_user_id)
             VALUES (:t, :s, :a, :b, :lt, :st, :r, :u, :ap)',
        )->execute([
            't' => $tenantId, 's' => $staffId, 'a' => $startUtc, 'b' => $endUtc, 'lt' => $typeId,
            'st' => $status, 'r' => $reason, 'u' => $userId, 'ap' => $status === 'approved' ? $userId : null,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findLeave(int $tenantId, int $id): ?array
    {
        return $this->findIn('staff_leave', $tenantId, $id);
    }

    public function setLeaveStatus(int $tenantId, int $id, string $status, int $userId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE staff_leave SET status = :s, approved_by_user_id = IF(:s2 = \'approved\', :u, approved_by_user_id)
              WHERE tenant_id = :t AND id = :id',
        )->execute(['s' => $status, 's2' => $status, 'u' => $userId, 't' => $tenantId, 'id' => $id]);
    }

    public function leaveTypeExists(int $tenantId, int $typeId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM staff_leave_types WHERE tenant_id = :t AND id = :id AND is_active = 1');
        $stmt->execute(['t' => $tenantId, 'id' => $typeId]);

        return $stmt->fetchColumn() !== false;
    }

    /* ----------------------------------------------- closures / special hours */

    /**
     * @param list<int> $branchIds
     * @return list<array<string, mixed>>
     */
    public function closures(int $tenantId, array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $params = ['t' => $tenantId];
        $stmt = $this->db->pdo()->prepare(
            'SELECT o.id, o.branch_id, b.name AS branch_name, o.local_date, o.is_closed,
                    TIME_FORMAT(o.opens_at, \'%H:%i\') AS opens_at, TIME_FORMAT(o.closes_at, \'%H:%i\') AS closes_at, o.reason
               FROM schedule_overrides o
               JOIN branches b ON b.tenant_id = o.tenant_id AND b.id = o.branch_id
              WHERE o.tenant_id = :t AND o.branch_id IN (' . $this->marks($branchIds, $params) . ')
                AND o.local_date >= UTC_DATE() - INTERVAL 1 DAY
              ORDER BY o.local_date, b.name
              LIMIT 200',
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    /** One override per branch per day: saving the same day replaces it. */
    public function saveClosure(int $tenantId, int $branchId, string $date, bool $closed, ?string $opens, ?string $closes, ?string $reason, int $userId): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM schedule_overrides WHERE tenant_id = :t AND branch_id = :b AND local_date = :d')
            ->execute(['t' => $tenantId, 'b' => $branchId, 'd' => $date]);
        $pdo->prepare(
            'INSERT INTO schedule_overrides (tenant_id, branch_id, local_date, is_closed, opens_at, closes_at, reason, created_by_user_id)
             VALUES (:t, :b, :d, :c, :o, :cl, :r, :u)',
        )->execute([
            't' => $tenantId, 'b' => $branchId, 'd' => $date, 'c' => $closed ? 1 : 0,
            'o' => $closed ? null : $opens, 'cl' => $closed ? null : $closes, 'r' => $reason, 'u' => $userId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findClosure(int $tenantId, int $id): ?array
    {
        return $this->findIn('schedule_overrides', $tenantId, $id);
    }

    public function deleteClosure(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM schedule_overrides WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => $tenantId, 'id' => $id]);
    }

    /* ------------------------------------------------------ blocked time */

    /**
     * Upcoming blocks for these staff or branches.
     *
     * @param list<int> $staffIds
     * @param list<int> $branchIds
     * @return list<array<string, mixed>>
     */
    public function blocks(int $tenantId, array $staffIds, array $branchIds): array
    {
        $params = ['t' => $tenantId];
        $or = [];
        if ($staffIds !== []) {
            $or[] = 'p.staff_id IN (' . $this->marks($staffIds, $params, 's') . ')';
        }
        if ($branchIds !== []) {
            $or[] = 'p.branch_id IN (' . $this->marks($branchIds, $params, 'b') . ')';
        }
        if ($or === []) {
            return [];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT p.id, p.staff_id, p.branch_id, s.display_name AS staff_name, b.name AS branch_name,
                    p.starts_at, p.ends_at, p.reason
               FROM blocked_periods p
               LEFT JOIN staff s ON s.tenant_id = p.tenant_id AND s.id = p.staff_id
               LEFT JOIN branches b ON b.tenant_id = p.tenant_id AND b.id = p.branch_id
              WHERE p.tenant_id = :t AND p.resource_id IS NULL AND (' . implode(' OR ', $or) . ')
                AND p.ends_at > UTC_TIMESTAMP()
              ORDER BY p.starts_at
              LIMIT 200',
        );
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    public function addBlock(int $tenantId, ?int $staffId, ?int $branchId, string $startUtc, string $endUtc, ?string $reason, int $userId): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO blocked_periods (tenant_id, branch_id, staff_id, starts_at, ends_at, reason, created_by_user_id)
             VALUES (:t, :b, :s, :a, :e, :r, :u)',
        )->execute(['t' => $tenantId, 'b' => $branchId, 's' => $staffId, 'a' => $startUtc, 'e' => $endUtc, 'r' => $reason, 'u' => $userId]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findBlock(int $tenantId, int $id): ?array
    {
        return $this->findIn('blocked_periods', $tenantId, $id);
    }

    public function deleteBlock(int $tenantId, int $id): void
    {
        $this->db->pdo()->prepare('DELETE FROM blocked_periods WHERE tenant_id = :t AND id = :id')
            ->execute(['t' => $tenantId, 'id' => $id]);
    }

    /**
     * Live appointments for a staff member or branch that overlap a window —
     * shown as a warning when blocking time or adding leave over bookings.
     */
    public function overlappingAppointments(int $tenantId, ?int $staffId, ?int $branchId, string $fromUtc, string $toUtc): int
    {
        $column = $staffId !== null ? 'staff_id' : 'branch_id';
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM appointments
              WHERE tenant_id = :t AND {$column} = :x
                AND status IN ('pending', 'confirmed', 'checked_in', 'in_service')
                AND start_at < :to AND end_at > :from",
        );
        $stmt->execute(['t' => $tenantId, 'x' => $staffId ?? $branchId, 'to' => $toUtc, 'from' => $fromUtc]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------ helpers */

    /** @return array<string, mixed>|null */
    private function findIn(string $table, int $tenantId, int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT * FROM {$table} WHERE tenant_id = :t AND id = :id LIMIT 1");
        $stmt->execute(['t' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Named placeholders for an IN list (native prepares cannot reuse names).
     *
     * @param list<int> $ids
     * @param array<string, mixed> $params
     */
    private function marks(array $ids, array &$params, string $prefix = 'i'): string
    {
        $marks = [];
        foreach (array_values($ids) as $i => $id) {
            $key = $prefix . $i;
            $marks[] = ':' . $key;
            $params[$key] = (int) $id;
        }

        return implode(',', $marks);
    }
}
