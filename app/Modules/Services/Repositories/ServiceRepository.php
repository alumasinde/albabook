<?php

declare(strict_types=1);

namespace App\Modules\Services\Repositories;

use App\Core\Database\Database;

/**
 * Tenant-scoped service catalogue queries. Every statement is pinned by
 * tenant_id; callers never pass it through request data.
 */
final class ServiceRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{q?: string, status?: string, category?: int, branch?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters, int $page, int $perPage): array
    {
        $where = ['s.tenant_id = :t'];
        $params = ['t' => $tenantId];

        if (($filters['q'] ?? '') !== '') {
            $where[] = 's.name LIKE :q';
            $params['q'] = '%' . $filters['q'] . '%';
        }
        if (($filters['status'] ?? '') === 'active' || ($filters['status'] ?? '') === 'inactive') {
            $where[] = 's.is_active = :active';
            $params['active'] = $filters['status'] === 'active' ? 1 : 0;
        }
        if (!empty($filters['category'])) {
            $where[] = 's.service_category_id = :cat';
            $params['cat'] = $filters['category'];
        }
        if (!empty($filters['branch'])) {
            $where[] = 'EXISTS (SELECT 1 FROM service_branches sb WHERE sb.tenant_id = s.tenant_id
                        AND sb.service_id = s.id AND sb.branch_id = :br)';
            $params['br'] = $filters['branch'];
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->pdo()->prepare("SELECT COUNT(*) FROM services s WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.uuid, s.name, s.description, s.duration_minutes,
                    s.buffer_before_minutes, s.buffer_after_minutes, s.price_type,
                    s.price_amount_minor, s.min_amount_minor, s.max_amount_minor,
                    s.currency, s.is_active, s.is_bookable_online, s.sort_position,
                    s.service_category_id, c.name AS category_name,
                    (SELECT rt.name FROM service_resource_requirements srr
                        JOIN resource_types rt ON rt.tenant_id = srr.tenant_id AND rt.id = srr.resource_type_id
                     WHERE srr.tenant_id = s.tenant_id AND srr.service_id = s.id
                     ORDER BY rt.name LIMIT 1) AS resource_name
             FROM services s
             LEFT JOIN service_categories c ON c.tenant_id = s.tenant_id AND c.id = s.service_category_id
             WHERE {$whereSql}
             ORDER BY s.sort_position, s.name
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
            'SELECT s.*, c.name AS category_name FROM services s
             LEFT JOIN service_categories c ON c.tenant_id = s.tenant_id AND c.id = s.service_category_id
             WHERE s.tenant_id = :t AND s.uuid = :u LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'u' => $uuid]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<int> branch ids the service is offered at */
    public function branchIds(int $tenantId, int $serviceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT branch_id FROM service_branches WHERE tenant_id = :t AND service_id = :s',
        );
        $stmt->execute(['t' => $tenantId, 's' => $serviceId]);

        /** @var list<int> */
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Branch ids a membership may manage: null means all branches.
     * @return list<int>|null
     */
    public function membershipBranchIds(int $tenantId, int $tenantUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT branch_id FROM membership_branches WHERE tenant_id = :t AND tenant_user_id = :m',
        );
        $stmt->execute(['t' => $tenantId, 'm' => $tenantUserId]);
        /** @var list<int> $ids */
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        return $ids === [] ? null : $ids;
    }

    /** @return list<array{id: int, name: string, timezone: string}> */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name, timezone FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string, timezone: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{id: int, name: string}> */
    public function categories(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM service_categories WHERE tenant_id = :t AND is_active = 1 ORDER BY sort_position, name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @return list<array{id: int, name: string}> */
    public function resourceTypes(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM resource_types WHERE tenant_id = :t ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO services (uuid, tenant_id, service_category_id, name, description,
                duration_minutes, buffer_before_minutes, buffer_after_minutes, price_type,
                price_amount_minor, min_amount_minor, max_amount_minor, currency,
                is_active, is_bookable_online, sort_position, created_at, updated_at)
             VALUES (:uuid, :tenant_id, :service_category_id, :name, :description,
                :duration_minutes, :buffer_before_minutes, :buffer_after_minutes, :price_type,
                :price_amount_minor, :min_amount_minor, :max_amount_minor, :currency,
                :is_active, :is_bookable_online, :sort_position, NOW(), NOW())',
        )->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $serviceId, array $data): void
    {
        $this->db->pdo()->prepare(
            'UPDATE services SET service_category_id = :service_category_id, name = :name,
                description = :description, duration_minutes = :duration_minutes,
                buffer_before_minutes = :buffer_before_minutes, buffer_after_minutes = :buffer_after_minutes,
                price_type = :price_type, price_amount_minor = :price_amount_minor,
                min_amount_minor = :min_amount_minor, max_amount_minor = :max_amount_minor,
                currency = :currency, is_active = :is_active, is_bookable_online = :is_bookable_online,
                sort_position = :sort_position, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id',
        )->execute($data + ['tenant_id' => $tenantId, 'id' => $serviceId]);
    }

    /** @param list<int> $branchIds */
    public function syncBranches(int $tenantId, int $serviceId, array $branchIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM service_branches WHERE tenant_id = :t AND service_id = :s')
            ->execute(['t' => $tenantId, 's' => $serviceId]);
        $insert = $pdo->prepare(
            'INSERT INTO service_branches (tenant_id, service_id, branch_id, is_bookable_online)
             VALUES (:t, :s, :b, 1)',
        );
        foreach ($branchIds as $branchId) {
            $insert->execute(['t' => $tenantId, 's' => $serviceId, 'b' => $branchId]);
        }
    }

    /** Single resource-type requirement per service (v1 of the m:n model). */
    public function syncResourceRequirement(int $tenantId, int $serviceId, ?int $resourceTypeId): void
    {
        $pdo = $this->db->pdo();
        if ($resourceTypeId === null) {
            $pdo->prepare('DELETE FROM service_resource_requirements WHERE tenant_id = :t AND service_id = :s')
                ->execute(['t' => $tenantId, 's' => $serviceId]);

            return;
        }
        $pdo->prepare(
            'DELETE FROM service_resource_requirements WHERE tenant_id = :t AND service_id = :s',
        )->execute(['t' => $tenantId, 's' => $serviceId]);
        $pdo->prepare(
            'INSERT INTO service_resource_requirements (tenant_id, service_id, variant_id, resource_type_id, quantity)
             VALUES (:t, :s, NULL, :rt, 1)',
        )->execute(['t' => $tenantId, 's' => $serviceId, 'rt' => $resourceTypeId]);
    }

    public function requiredResourceType(int $tenantId, int $serviceId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT resource_type_id FROM service_resource_requirements
             WHERE tenant_id = :t AND service_id = :s LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 's' => $serviceId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function appointmentCount(int $tenantId, int $serviceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM appointment_items WHERE tenant_id = :t AND service_id = :s',
        );
        $stmt->execute(['t' => $tenantId, 's' => $serviceId]);

        return (int) $stmt->fetchColumn();
    }

    /** References that block deletion (RESTRICT FKs). */
    public function referenceCount(int $tenantId, int $serviceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM appointment_items WHERE tenant_id = :t1 AND service_id = :s1)
              + (SELECT COUNT(*) FROM customers WHERE tenant_id = :t2 AND preferred_service_id = :s2)',
        );
        $stmt->execute(['t1' => $tenantId, 's1' => $serviceId, 't2' => $tenantId, 's2' => $serviceId]);

        return (int) $stmt->fetchColumn();
    }

    public function delete(int $tenantId, int $serviceId): void
    {
        $this->db->pdo()->prepare('DELETE FROM services WHERE tenant_id = :t AND id = :s')
            ->execute(['t' => $tenantId, 's' => $serviceId]);
    }
}
