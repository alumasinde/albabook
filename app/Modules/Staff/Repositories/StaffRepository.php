<?php

declare(strict_types=1);

namespace App\Modules\Staff\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped staff directory queries. Every statement is pinned by
 * tenant_id; schedules branch through staff_branches (composite FK).
 */
final class StaffRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{q?: string, status?: string, branch?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters, int $page, int $perPage): array
    {
        $where = ['s.tenant_id = :t'];
        $params = ['t' => $tenantId];

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(s.first_name LIKE :q OR s.last_name LIKE :q2 OR s.display_name LIKE :q3)';
            $params['q'] = '%' . $filters['q'] . '%';
            $params['q2'] = '%' . $filters['q'] . '%';
            $params['q3'] = '%' . $filters['q'] . '%';
        }
        if (($filters['status'] ?? '') === 'active' || ($filters['status'] ?? '') === 'inactive') {
            $where[] = 's.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['branch'])) {
            $where[] = 'EXISTS (SELECT 1 FROM staff_branches sb WHERE sb.tenant_id = s.tenant_id
                        AND sb.staff_id = s.id AND sb.branch_id = :br)';
            $params['br'] = $filters['branch'];
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM staff s WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.uuid, s.display_name, s.first_name, s.last_name, s.email,
                    s.phone_e164, s.booking_priority, s.is_bookable_online, s.status,
                    (SELECT COUNT(*) FROM staff_services ss WHERE ss.tenant_id = s.tenant_id AND ss.staff_id = s.id) AS service_count,
                    (SELECT GROUP_CONCAT(b.name ORDER BY b.name SEPARATOR ', ')
                       FROM staff_branches sb2 JOIN branches b
                         ON b.tenant_id = sb2.tenant_id AND b.id = sb2.branch_id
                      WHERE sb2.tenant_id = s.tenant_id AND sb2.staff_id = s.id) AS branch_names
             FROM staff s
             WHERE {$whereSql}
             ORDER BY s.booking_priority, s.display_name
             LIMIT {$perPage} OFFSET {$offset}",
        );
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM staff WHERE tenant_id = :t AND uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<int> */
    public function branchIds(int $tenantId, int $staffId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT branch_id FROM staff_branches WHERE tenant_id = :t AND staff_id = :s',
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId]);

        /** @var list<int> */
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public function serviceIds(int $tenantId, int $staffId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT service_id FROM staff_services WHERE tenant_id = :t AND staff_id = :s',
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId]);

        /** @var list<int> */
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Weekly schedule rows for a staff member (any branch).
     *
     * @return list<array{branch_id: int, weekday: int, starts_at: string, ends_at: string}>
     */
    public function schedules(int $tenantId, int $staffId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT branch_id, weekday, starts_at, ends_at FROM staff_schedules
             WHERE tenant_id = :t AND staff_id = :s ORDER BY branch_id, weekday',
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId]);

        /** @var list<array{branch_id: int, weekday: int, starts_at: string, ends_at: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{id: int, name: string}> */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{id: int, name: string}> */
    public function services(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM services WHERE tenant_id = :t AND is_active = 1 ORDER BY sort_position, name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /**
     * Active memberships for the optional login link.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function members(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT tu.id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS name, u.email
             FROM tenant_users tu JOIN users u ON u.id = tu.user_id
             WHERE tu.tenant_id = :t AND tu.status = 'active' ORDER BY name",
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string, email: string}> */
        return array_values($stmt->fetchAll());
    }

    public function membershipValid(int $tenantId, int $tenantUserId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM tenant_users WHERE tenant_id = :t AND id = :m AND status = 'active'",
        );
        $stmt->execute(['t' => $tenantId, 'm' => $tenantUserId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO staff (uuid, tenant_id, tenant_user_id, first_name, last_name, display_name,
                email, phone_e164, bio, booking_priority, is_bookable_online, status, created_at, updated_at)
             VALUES (:uuid, :tenant_id, :tenant_user_id, :first_name, :last_name, :display_name,
                :email, :phone_e164, :bio, :booking_priority, :is_bookable_online, :status, NOW(), NOW())',
        )->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $staffId, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE staff SET tenant_user_id = :tenant_user_id, first_name = :first_name,
                last_name = :last_name, display_name = :display_name, email = :email,
                phone_e164 = :phone_e164, bio = :bio, booking_priority = :booking_priority,
                is_bookable_online = :is_bookable_online, status = :status, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
        )->execute($data + ['tenant_id' => $tenantId, 'id' => $staffId]);
    }

    /** @param list<int> $branchIds */
    public function syncBranches(int $tenantId, int $staffId, array $branchIds): void
    {
        $pdo = $this->db->pdo();
        // staff_schedules rows cascade with their staff_branches assignment.
        $pdo->prepare('DELETE FROM staff_branches WHERE tenant_id = :t AND staff_id = :s')
            ->execute(['t' => $tenantId, 's' => $staffId]);
        $insert = $pdo->prepare('INSERT INTO staff_branches (tenant_id, staff_id, branch_id) VALUES (:t, :s, :b)');
        foreach ($branchIds as $branchId) {
            $insert->execute(['t' => $tenantId, 's' => $staffId, 'b' => $branchId]);
        }
    }

    /** @param list<int> $serviceIds */
    public function syncServices(int $tenantId, int $staffId, array $serviceIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM staff_services WHERE tenant_id = :t AND staff_id = :s')
            ->execute(['t' => $tenantId, 's' => $staffId]);
        $insert = $pdo->prepare(
            'INSERT INTO staff_services (tenant_id, staff_id, service_id, is_bookable_online)
             VALUES (:t, :s, :svc, 1)',
        );
        foreach ($serviceIds as $serviceId) {
            $insert->execute(['t' => $tenantId, 's' => $staffId, 'svc' => $serviceId]);
        }
    }

    /**
     * Replace the weekly schedule. Rows are written for every assigned branch
     * (staff_schedules is per-branch via composite FK).
     *
     * @param list<int> $branchIds
     * @param list<array{weekday: int, starts_at: string, ends_at: string}> $rows
     */
    public function syncSchedules(int $tenantId, int $staffId, array $branchIds, array $rows): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM staff_schedules WHERE tenant_id = :t AND staff_id = :s')
            ->execute(['t' => $tenantId, 's' => $staffId]);
        if ($rows === [] || $branchIds === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO staff_schedules (tenant_id, staff_id, branch_id, weekday, starts_at, ends_at, created_at, updated_at)
             VALUES (:t, :s, :b, :w, :o, :c, NOW(), NOW())',
        );
        foreach ($branchIds as $branchId) {
            foreach ($rows as $row) {
                $insert->execute([
                    't' => $tenantId, 's' => $staffId, 'b' => $branchId,
                    'w' => $row['weekday'], 'o' => $row['starts_at'], 'c' => $row['ends_at'],
                ]);
            }
        }
    }

    /** References that block deletion (RESTRICT FKs). */
    public function referenceCount(int $tenantId, int $staffId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM appointment_items WHERE tenant_id = :t1 AND staff_id = :s1)
              + (SELECT COUNT(*) FROM appointments WHERE tenant_id = :t2 AND staff_id = :s2)
              + (SELECT COUNT(*) FROM booking_hold_items WHERE tenant_id = :t3 AND staff_id = :s3)
              + (SELECT COUNT(*) FROM customers WHERE tenant_id = :t4 AND preferred_staff_id = :s4)',
        );
        $stmt->execute([
            't1' => $tenantId, 's1' => $staffId,
            't2' => $tenantId, 's2' => $staffId,
            't3' => $tenantId, 's3' => $staffId,
            't4' => $tenantId, 's4' => $staffId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $tenantId, int $staffId): void
    {
        $this->db->pdo()->prepare('DELETE FROM staff WHERE tenant_id = :t AND id = :s')
            ->execute(['t' => $tenantId, 's' => $staffId]);
    }
}
