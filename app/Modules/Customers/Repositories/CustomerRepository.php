<?php

declare(strict_types=1);

namespace App\Modules\Customers\Repositories;

use App\Core\Database\Database;
use PDO;

/**
 * Tenant-scoped customer directory access. All queries pin tenant_id — a
 * customer row can never be read or mutated across tenants.
 */
final class CustomerRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array{q?: string, status?: string} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters, int $page, int $perPage): array
    {
        $where = ['c.tenant_id = :t'];
        $params = ['t' => $tenantId];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = "(c.first_name LIKE :q OR c.last_name LIKE :q2 OR c.email LIKE :q3 OR c.phone_e164 LIKE :q4)";
            $like = '%' . $q . '%';
            $params['q'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
            $params['q4'] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['active', 'merged', 'anonymised'], true)) {
            $where[] = 'c.status = :st';
            $params['st'] = $status;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->pdo()->prepare(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM appointments a WHERE a.tenant_id = c.tenant_id AND a.customer_id = c.id) AS appointment_count
               FROM customers c
              WHERE {$whereSql}
              ORDER BY c.last_name, c.first_name
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
            'SELECT * FROM customers WHERE tenant_id = :t AND uuid = :u LIMIT 1',
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
            'INSERT INTO customers (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($data);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $tenantId, int $customerId, array $data): void
    {
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));
        $stmt = $this->db->pdo()->prepare(
            "UPDATE customers SET {$sets} WHERE tenant_id = :__t AND id = :__id",
        );
        $stmt->execute($data + ['__t' => $tenantId, '__id' => $customerId]);
    }

    public function delete(int $tenantId, int $customerId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM customers WHERE tenant_id = :t AND id = :i',
        );
        $stmt->execute(['t' => $tenantId, 'i' => $customerId]);
    }

    /**
     * Rows referencing this customer via RESTRICT FKs (appointments, payments,
     * other customers merged into it). >0 blocks deletion.
     */
    public function referenceCount(int $tenantId, int $customerId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT (SELECT COUNT(*) FROM appointments WHERE tenant_id = :t1 AND customer_id = :s1)
              + (SELECT COUNT(*) FROM payments WHERE tenant_id = :t2 AND customer_id = :s2)
              + (SELECT COUNT(*) FROM customers WHERE tenant_id = :t3 AND merged_into_customer_id = :s3)',
        );
        $stmt->execute([
            't1' => $tenantId, 's1' => $customerId,
            't2' => $tenantId, 's2' => $customerId,
            't3' => $tenantId, 's3' => $customerId,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function appointmentCount(int $tenantId, int $customerId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM appointments WHERE tenant_id = :t AND customer_id = :c',
        );
        $stmt->execute(['t' => $tenantId, 'c' => $customerId]);

        return (int) $stmt->fetchColumn();
    }

    /** Active staff for the preferred-staff dropdown.
     * @return list<array{id: int, display_name: string}>
     */
    public function staffOptions(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, display_name FROM staff WHERE tenant_id = :t AND status = 'active' ORDER BY display_name",
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, display_name: string}> */
        return array_values($stmt->fetchAll());
    }

    /** Active services for the preferred-service dropdown.
     * @return list<array{id: int, name: string}>
     */
    public function serviceOptions(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM services WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        /** @var list<array{id: int, name: string}> */
        return array_values($stmt->fetchAll());
    }

    public function staffValid(int $tenantId, int $staffId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM staff WHERE tenant_id = :t AND id = :s AND status = 'active' LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 's' => $staffId]);

        return $stmt->fetchColumn() !== false;
    }

    public function serviceValid(int $tenantId, int $serviceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM services WHERE tenant_id = :t AND id = :s AND is_active = 1 LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 's' => $serviceId]);

        return $stmt->fetchColumn() !== false;
    }
}
