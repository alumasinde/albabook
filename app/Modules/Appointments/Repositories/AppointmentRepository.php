<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped appointment reads and writes. Every query carries tenant_id;
 * branch scope is an additional hard filter, never a hint (dashboard.md §9).
 * All datetimes are UTC strings (DateTimeService::DB_FORMAT).
 */
final class AppointmentRepository
{
    public function __construct(private readonly Database $db) {}

    private const LIST_SELECT =
        'SELECT a.id, a.uuid, a.reference, a.status, a.payment_status, a.start_at, a.end_at,
                a.customer_name_snapshot, a.customer_phone_snapshot, a.customer_email_snapshot,
                a.total_amount_minor, a.currency, a.version, a.staff_id, a.branch_id,
                b.name AS branch_name, b.timezone AS branch_timezone, s.display_name AS staff_name,
                (SELECT GROUP_CONCAT(DISTINCT ai2.service_name_snapshot ORDER BY ai2.item_position SEPARATOR ", ")
                   FROM appointment_items ai2
                  WHERE ai2.tenant_id = a.tenant_id AND ai2.appointment_id = a.id) AS service_names,
                (SELECT ai3.duration_minutes FROM appointment_items ai3
                  WHERE ai3.tenant_id = a.tenant_id AND ai3.appointment_id = a.id
                  ORDER BY ai3.item_position LIMIT 1) AS first_duration
          FROM appointments a
          JOIN branches b ON b.tenant_id = a.tenant_id AND b.id = a.branch_id
     LEFT JOIN staff s ON s.tenant_id = a.tenant_id AND s.id = a.staff_id';

    /**
     * Shared WHERE clause + params for list/calendar/count queries.
     *
     * @param array<int>|null $branchIds null = no scope restriction
     * @param array<string, mixed> $filters keys: q,status,service,staff,branch,from,to,date
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function scopedWhere(int $tenantId, ?array $branchIds, array $filters): array
    {
        $where = ['a.tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];

        if ($branchIds !== null) {
            if ($branchIds === []) {
                $where[] = '1 = 0';
            } else {
                $marks = [];
                foreach (array_values($branchIds) as $i => $id) {
                    $key = 'scope_branch_' . $i;
                    $marks[] = ':' . $key;
                    $params[$key] = $id;
                }
                $where[] = 'a.branch_id IN (' . implode(',', $marks) . ')';
            }
        }
        if (($filters['branch'] ?? '') !== '') {
            $where[] = 'a.branch_id = :f_branch';
            $params['f_branch'] = (int) $filters['branch'];
        }
        if (($filters['staff'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM appointment_items ai_f WHERE ai_f.tenant_id = a.tenant_id AND ai_f.appointment_id = a.id AND ai_f.staff_id = :f_staff)';
            $params['f_staff'] = (int) $filters['staff'];
        }
        if (($filters['service'] ?? '') !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM appointment_items ai_s WHERE ai_s.tenant_id = a.tenant_id AND ai_s.appointment_id = a.id AND ai_s.service_id = :f_service)';
            $params['f_service'] = (int) $filters['service'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'a.status = :f_status';
            $params['f_status'] = (string) $filters['status'];
        }
        if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '') {
            $where[] = 'a.start_at < :f_to AND a.end_at >= :f_from';
            $params['f_from'] = $filters['from'];
            $params['f_to'] = $filters['to'];
        } elseif (($filters['date'] ?? '') !== '') {
            $where[] = 'a.start_at < :f_date_end AND a.end_at >= :f_date_start';
            $params['f_date_start'] = $filters['date'] . ' 00:00:00';
            $params['f_date_end'] = $filters['date'] . ' 23:59:59.999999';
        } elseif (($filters['to'] ?? '') !== '') {
            $where[] = 'a.start_at < :f_to';
            $params['f_to'] = $filters['to'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(a.customer_name_snapshot LIKE :f_q OR a.customer_phone_snapshot LIKE :f_q OR a.reference = :f_q_exact)';
            $params['f_q'] = '%' . $filters['q'] . '%';
            $params['f_q_exact'] = (string) $filters['q'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<int>|null $branchIds
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, ?array $branchIds, array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->scopedWhere($tenantId, $branchIds, $filters);
        $pdo = $this->db->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM appointments a WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $pdo->prepare(self::LIST_SELECT . ' WHERE ' . $where . ' ORDER BY a.start_at ASC, a.id ASC LIMIT ' . $perPage . ' OFFSET ' . $offset);
        $stmt->execute($params);

        return ['rows' => array_values($stmt->fetchAll()), 'total' => $total];
    }

    /**
     * @param array<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function forRange(int $tenantId, ?array $branchIds, string $fromUtc, string $toUtc, ?int $staffId): array
    {
        [$where, $params] = $this->scopedWhere($tenantId, $branchIds, [
            'from' => $fromUtc,
            'to' => $toUtc,
            'staff' => $staffId !== null ? (string) $staffId : '',
        ]);
        $stmt = $this->db->pdo()->prepare(self::LIST_SELECT . ' WHERE ' . $where . ' ORDER BY a.start_at ASC, a.id ASC');
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    /**
     * Status counts for the KPI cards. Period bounds are UTC ranges computed
     * by the caller in the tenant timezone (dashboard.md §2 rules).
     *
     * @param array<int>|null $branchIds
     * @return array{today: int, confirmed: int, pending: int, cancelled: int}
     */
    public function dayStats(int $tenantId, ?array $branchIds, string $dayFromUtc, string $dayToUtc): array
    {
        [$where, $params] = $this->scopedWhere($tenantId, $branchIds, ['from' => $dayFromUtc, 'to' => $dayToUtc]);
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.status, COUNT(*) AS n FROM appointments a WHERE ' . $where . ' GROUP BY a.status',
        );
        $stmt->execute($params);
        $counts = ['today' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $status = (string) $row['status'];
            $n = (int) $row['n'];
            if ($status !== 'cancelled' && $status !== 'rejected' && $status !== 'expired') {
                $counts['today'] += $n;
            }
            if (array_key_exists($status, $counts)) {
                $counts[$status] += $n;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        [$where, $params] = $this->scopedWhere($tenantId, null, []);
        $stmt = $this->db->pdo()->prepare(self::LIST_SELECT . ' WHERE ' . $where . ' AND a.uuid = :f_uuid LIMIT 1');
        $stmt->execute($params + ['f_uuid' => $uuid]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function lockForUpdate(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM appointments WHERE tenant_id = :t AND uuid = :u FOR UPDATE',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function itemsFor(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ai.service_name_snapshot, ai.variant_name_snapshot, ai.duration_minutes,
                    ai.start_at, ai.end_at, ai.unit_amount_minor, ai.quantity, ai.currency,
                    s.display_name AS staff_name
               FROM appointment_items ai
          LEFT JOIN staff s ON s.tenant_id = ai.tenant_id AND s.id = ai.staff_id
              WHERE ai.tenant_id = :t AND ai.appointment_id = :a
           ORDER BY ai.item_position',
        );
        $stmt->execute(['t' => $tenantId, 'a' => $appointmentId]);

        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function eventsFor(int $tenantId, int $appointmentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT event_type, actor_type, from_status, to_status, reason, created_at
               FROM appointment_events
              WHERE tenant_id = :t AND appointment_id = :a
           ORDER BY created_at, id',
        );
        $stmt->execute(['t' => $tenantId, 'a' => $appointmentId]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Optimistic status transition: updates status + bumps version only when the
     * expected version still matches. Returns the new version or null on stale.
     *
     * @param array<string, mixed> $extra
     */
    public function updateStatus(int $tenantId, int $id, string $status, int $expectedVersion, array $extra = []): ?int
    {
        $sets = ['status = :status', 'version = version + 1'];
        $params = ['status' => $status, 't' => $tenantId, 'id' => $id, 'v' => $expectedVersion];
        if ($status === 'cancelled') {
            $sets[] = 'cancelled_at = UTC_TIMESTAMP(6)';
            $sets[] = 'cancelled_by = :cancelled_by';
            $sets[] = 'cancellation_reason = :cancellation_reason';
            $params['cancelled_by'] = $extra['cancelled_by'] ?? 'business';
            $params['cancellation_reason'] = $extra['reason'] ?? null;
        }
        $sql = 'UPDATE appointments SET ' . implode(', ', $sets)
             . ' WHERE tenant_id = :t AND id = :id AND version = :v';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() === 1 ? $expectedVersion + 1 : null;
    }

    public function insertEvent(int $tenantId, int $appointmentId, string $eventType, ?string $actorType, ?int $actorId, ?string $from, string $to, ?string $reason = null): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO appointment_events (tenant_id, appointment_id, event_type, actor_type, actor_id, from_status, to_status, reason)
             VALUES (:t, :a, :type, :actor_type, :actor_id, :from, :to, :reason)',
        )->execute([
            't' => $tenantId,
            'a' => $appointmentId,
            'type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'from' => $from,
            'to' => $to,
            'reason' => $reason,
        ]);
    }

    /**
     * Count occupied windows for a staff member overlapping [fromUtc, toUtc)
     * on statuses that block time (appointment_statuses.blocks_time).
     */
    public function staffOccupied(int $tenantId, int $staffId, string $fromUtc, string $toUtc): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM appointment_items ai
              JOIN appointments a ON a.tenant_id = ai.tenant_id AND a.id = ai.appointment_id
              WHERE ai.tenant_id = :t AND ai.staff_id = :s
                AND ai.occupied_start_at < :to AND ai.occupied_end_at > :from
                AND a.status IN ('pending', 'confirmed', 'checked_in')",
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId, 'from' => $fromUtc, 'to' => $toUtc]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Lock the staff row (D-009 anchor) inside a transaction.
     * @return array<string, mixed>|null
     */
    public function lockStaff(int $tenantId, int $staffId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, display_name, status FROM staff WHERE tenant_id = :t AND id = :s FOR UPDATE',
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array<int>|null $branchIds
     * @return list<array<string, mixed>>
     */
    public function branchesFor(int $tenantId, ?array $branchIds): array
    {
        $where = 'tenant_id = :t AND is_active = 1';
        $params = ['t' => $tenantId];
        if ($branchIds !== null) {
            if ($branchIds === []) {
                return [];
            }
            $marks = [];
            foreach (array_values($branchIds) as $i => $id) {
                $marks[] = ':b' . $i;
                $params['b' . $i] = $id;
            }
            $where .= ' AND id IN (' . implode(',', $marks) . ')';
        }
        $stmt = $this->db->pdo()->prepare("SELECT id, name, timezone FROM branches WHERE {$where} ORDER BY name");
        $stmt->execute($params);

        return array_values($stmt->fetchAll());
    }

    /**
     * The staff record linked to a membership (staff.tenant_user_id, D-007).
     * "My appointments" (appointments.view_own) filters on it.
     */
    public function ownStaffId(int $tenantId, int $tenantUserId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id FROM staff WHERE tenant_id = :t AND tenant_user_id = :m LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'm' => $tenantUserId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return list<array<string, mixed>> */
    public function staffFor(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.display_name AS name FROM staff s WHERE s.tenant_id = :t AND s.status = 'active' ORDER BY s.display_name",
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function servicesFor(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, duration_minutes FROM services WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function serviceFor(int $tenantId, int $serviceId, ?int $branchId = null): ?array
    {
        $params = ['t' => $tenantId, 'id' => $serviceId];
        $branchJoin = '';
        if ($branchId !== null) {
            $branchJoin = 'LEFT JOIN service_branches sb ON sb.tenant_id = s.tenant_id AND sb.service_id = s.id AND sb.branch_id = :b';
            $params['b'] = $branchId;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.*' . ($branchId !== null ? ', sb.price_amount_minor AS branch_price, sb.is_bookable_online AS branch_bookable' : '')
            . ' FROM services s ' . $branchJoin . ' WHERE s.tenant_id = :t AND s.id = :id AND s.is_active = 1',
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function policyVersionFor(int $tenantId, ?int $branchId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT policy_version FROM booking_policies WHERE tenant_id = :t AND branch_scope = :s LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 's' => $branchId ?? 0]);
        $v = $stmt->fetchColumn();
        if ($v === false && $branchId !== null) {
            $stmt->execute(['t' => $tenantId, 's' => 0]);
            $v = $stmt->fetchColumn();
        }

        return $v === false ? null : (int) $v;
    }

    /** @return array<string, mixed>|null */
    public function findCustomerByPhone(int $tenantId, string $phone): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.id FROM customer_match_keys mk
              JOIN customers c ON c.tenant_id = mk.tenant_id AND c.id = mk.customer_id
              WHERE mk.tenant_id = :t AND mk.match_type = 'phone' AND mk.match_value = :v
                AND c.status = 'active' LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'v' => $phone]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $row */
    public function insertAppointment(array $row): int
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO appointments (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    public function insertItem(array $row): void
    {
        $columns = array_keys($row);
        $sql = 'INSERT INTO appointment_items (' . implode(',', $columns) . ') VALUES (' . implode(',', array_map(static fn($c) => ':' . $c, $columns)) . ')';
        $this->db->pdo()->prepare($sql)->execute($row);
    }
}
