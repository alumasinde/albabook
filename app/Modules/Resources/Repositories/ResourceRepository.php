<?php

declare(strict_types=1);

namespace App\Modules\Resources\Repositories;

use App\Core\Database\Database;
use PDO;

/**
 * Tenant-scoped resource (rooms, chairs, equipment) access. All queries pin
 * tenant_id — resources can never cross tenants.
 */
final class ResourceRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{q?: string, status?: string, branch?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters, int $page, int $perPage): array
    {
        $where = ['r.tenant_id = :t'];
        $params = ['t' => $tenantId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = 'r.name LIKE :q';
            $params['q'] = '%' . $q . '%';
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'maintenance', 'inactive'], true)) {
            $where[] = 'r.status = :st';
            $params['st'] = $status;
        }

        $branchId = (int) ($filters['branch'] ?? 0);
        if ($branchId > 0) {
            $where[] = 'r.branch_id = :b';
            $params['b'] = $branchId;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM resources r WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT r.*, rt.name AS type_name, b.name AS branch_name
               FROM resources r
               JOIN resource_types rt ON rt.tenant_id = r.tenant_id AND rt.id = r.resource_type_id
               JOIN branches b ON b.tenant_id = r.tenant_id AND b.id = r.branch_id
              WHERE {$whereSql}
              ORDER BY r.name
              LIMIT :lim OFFSET :off",
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(int $tenantId, string $uuid): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM resources WHERE tenant_id = :t AND uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO resources (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $this->db->pdo()->prepare($sql)->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $resourceId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $this->db->pdo()->prepare(
            "UPDATE resources SET {$sets} WHERE tenant_id = :__t AND id = :__id",
        )->execute($data + ['__t' => $tenantId, '__id' => $resourceId]);
    }

    public function delete(int $tenantId, int $resourceId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM resources WHERE tenant_id = :t AND id = :i',
        )->execute(['t' => $tenantId, 'i' => $resourceId]);
    }

    /**
     * RESTRICT refs to a resource: appointment_items' resource assignments
     * (appointment_resources) and pending holds. resource_schedules,
     * blocked_periods and schedule_overrides CASCADE.
     */
    public function referenceCount(int $tenantId, int $resourceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM appointment_resources WHERE tenant_id = :t1 AND resource_id = :s1)
              + (SELECT COUNT(*) FROM booking_hold_resources WHERE tenant_id = :t2 AND resource_id = :s2)',
        );
        $stmt->execute([
            't1' => $tenantId, 's1' => $resourceId,
            't2' => $tenantId, 's2' => $resourceId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------ resource types */

    /** @return list<array{id: int, uuid: string, name: string, resources: int}> */
    public function types(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT rt.id, rt.uuid, rt.name,
                    (SELECT COUNT(*) FROM resources r WHERE r.tenant_id = rt.tenant_id AND r.resource_type_id = rt.id) AS resources
               FROM resource_types rt WHERE rt.tenant_id = :t ORDER BY rt.name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, uuid: string, name: string, resources: int}> */
        return array_values($stmt->fetchAll());
    }

    public function typeValid(int $tenantId, int $typeId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM resource_types WHERE tenant_id = :t AND id = :i LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'i' => $typeId]);

        return $stmt->fetchColumn() !== false;
    }

    /** Create a resource type, returning its id. */
    public function insertType(int $tenantId, string $uuid, string $name): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO resource_types (uuid, tenant_id, name) VALUES (:u, :t, :n)',
        )->execute(['u' => $uuid, 't' => $tenantId, 'n' => $name]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** RESTRICT refs to a type: resources and service_resource_requirements. */
    public function typeReferenceCount(int $tenantId, int $typeId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM resources WHERE tenant_id = :t1 AND resource_type_id = :s1)
              + (SELECT COUNT(*) FROM service_resource_requirements WHERE tenant_id = :t2 AND resource_type_id = :s2)',
        );
        $stmt->execute([
            't1' => $tenantId, 's1' => $typeId,
            't2' => $tenantId, 's2' => $typeId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteType(int $tenantId, int $typeId): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM resource_types WHERE tenant_id = :t AND id = :i',
        )->execute(['t' => $tenantId, 'i' => $typeId]);
    }

    /* -------------------------------------------------------- branch lookup */

    /** Active branches for the assignment dropdown.
     * @return list<array{id: int, name: string}>
     */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    public function branchValid(int $tenantId, int $branchId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM branches WHERE tenant_id = :t AND id = :i AND is_active = 1 LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'i' => $branchId]);

        return $stmt->fetchColumn() !== false;
    }

    /* ------------------------------------------------------------- schedule */

    /** @return list<array<string, mixed>> */
    public function schedules(int $tenantId, int $resourceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT weekday, starts_at, ends_at FROM resource_schedules
              WHERE tenant_id = :t AND resource_id = :r AND effective_from IS NULL AND effective_to IS NULL
              ORDER BY weekday',
        );
        $stmt->execute(['t' => $tenantId, 'r' => $resourceId]);

        /** @var list<array<string, mixed>> */
        return array_values($stmt->fetchAll());
    }

    /** @param list<array{weekday: int, starts_at: string, ends_at: string}> $schedule */
    public function syncSchedules(int $tenantId, int $resourceId, array $schedule): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM resource_schedules WHERE tenant_id = :t AND resource_id = :r
               AND effective_from IS NULL AND effective_to IS NULL',
        )->execute(['t' => $tenantId, 'r' => $resourceId]);

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO resource_schedules (tenant_id, resource_id, weekday, starts_at, ends_at)
             VALUES (:t, :r, :w, :s, :e)',
        );
        foreach ($schedule as $row) {
            $insert->execute([
                't' => $tenantId,
                'r' => $resourceId,
                'w' => $row['weekday'],
                's' => $row['starts_at'],
                'e' => $row['ends_at'],
            ]);
        }
    }
}
