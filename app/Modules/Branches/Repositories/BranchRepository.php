<?php

declare(strict_types=1);

namespace App\Modules\Branches\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped branch access. All queries pin tenant_id — branches can never
 * be read or mutated across tenants.
 */
final class BranchRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{q?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters): array
    {
        $where = ['b.tenant_id = :t'];
        $params = ['t' => $tenantId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(b.name LIKE :q OR b.town LIKE :q2 OR b.county LIKE :q3)';
            $like = '%' . $q . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM branches b WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM staff_branches sb WHERE sb.tenant_id = b.tenant_id AND sb.branch_id = b.id) AS staff_count,
                    (SELECT COUNT(*) FROM resources r WHERE r.tenant_id = b.tenant_id AND r.branch_id = b.id) AS resource_count
               FROM branches b
              WHERE {$whereSql}
              ORDER BY b.name",
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
            'SELECT * FROM branches WHERE tenant_id = :t AND uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    /** Live branch count for the max_branches plan gate. */
    public function countActive(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND is_active = 1',
        );
        $stmt->execute([$tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO branches (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $this->db->pdo()->prepare($sql)->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $branchId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "UPDATE branches SET {$sets} WHERE tenant_id = :__t AND id = :__id",
        )->execute($data + ['__t' => $tenantId, '__id' => $branchId]);
    }

    public function delete(int $tenantId, int $branchId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM branches WHERE tenant_id = :t AND id = :i',
        )->execute(['t' => $tenantId, 'i' => $branchId]);
    }

    /**
     * Rows referencing this branch via RESTRICT FKs. business_hours,
     * membership_branches, service_branches and staff_branches all CASCADE,
     * so only the restrictive tables block deletion.
     */
    public function referenceCount(int $tenantId, int $branchId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM appointments WHERE tenant_id = :t1 AND branch_id = :s1)
              + (SELECT COUNT(*) FROM payments WHERE tenant_id = :t2 AND branch_id = :s2)
              + (SELECT COUNT(*) FROM booking_holds WHERE tenant_id = :t3 AND branch_id = :s3)
              + (SELECT COUNT(*) FROM booking_policies WHERE tenant_id = :t4 AND branch_id = :s4)
              + (SELECT COUNT(*) FROM resources WHERE tenant_id = :t5 AND branch_id = :s5)
              + (SELECT COUNT(*) FROM tenant_payment_accounts WHERE tenant_id = :t6 AND branch_id = :s6)',
        );
        $stmt->execute([
            't1' => $tenantId, 's1' => $branchId,
            't2' => $tenantId, 's2' => $branchId,
            't3' => $tenantId, 's3' => $branchId,
            't4' => $tenantId, 's4' => $branchId,
            't5' => $tenantId, 's5' => $branchId,
            't6' => $tenantId, 's6' => $branchId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------- business hours */

    /** @return list<array<string, mixed>> */
    public function businessHours(int $tenantId, int $branchId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT weekday, opens_at, closes_at FROM business_hours
              WHERE tenant_id = :t AND branch_id = :b AND effective_from IS NULL AND effective_to IS NULL
              ORDER BY weekday',
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** Replace the default (non-dated) business-hours rows for a branch.
     * @param list<array{weekday: int, opens_at: string, closes_at: string}> $hours
     */
    public function syncBusinessHours(int $tenantId, int $branchId, array $hours): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM business_hours WHERE tenant_id = :t AND branch_id = :b
               AND effective_from IS NULL AND effective_to IS NULL',
        )->execute(['t' => $tenantId, 'b' => $branchId]);

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO business_hours (tenant_id, branch_id, weekday, opens_at, closes_at, created_at, updated_at)
             VALUES (:t, :b, :w, :o, :c, NOW(), NOW())',
        );
        foreach ($hours as $row) {
            $insert->execute([
                't' => $tenantId,
                'b' => $branchId,
                'w' => $row['weekday'],
                'o' => $row['opens_at'],
                'c' => $row['closes_at'],
            ]);
        }
    }
}
