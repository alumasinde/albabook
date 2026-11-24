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
     * @param list<int>|null $branchIds Branch-limited scope: only customers
     *        with at least one appointment at these branches are returned.
     *        NULL = unrestricted (tenant-wide visibility).
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters, int $page, int $perPage, ?array $branchIds = null): array
    {
        $where = ['c.tenant_id = :t'];
        $params = ['t' => $tenantId];

        if ($branchIds !== null) {
            [$scopeSql, $scopeParams] = $this->branchScope($branchIds);
            $where[] = $scopeSql;
            $params += $scopeParams;
        }

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

    /**
     * Customers are tenant-level entities (no branch_id): branch-limited
     * members may only see customers associated with their branches through
     * at least one appointment. Returns true when no restriction applies.
     *
     * @param list<int>|null $branchIds
     */
    public function visibleAtBranches(int $tenantId, int $customerId, ?array $branchIds): bool
    {
        if ($branchIds === null) {
            return true;
        }
        [$scopeSql, $scopeParams] = $this->branchScope($branchIds);
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM customers c WHERE c.tenant_id = :t AND c.id = :c AND ' . $scopeSql . ' LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'c' => $customerId] + $scopeParams);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * EXISTS clause binding :b0..:bN — a customer's branch association is
     * their appointments'. An empty list matches nothing.
     *
     * @param list<int> $branchIds
     * @return array{0: string, 1: array<string, int>}
     */
    private function branchScope(array $branchIds): array
    {
        $params = [];
        $placeholders = [];
        foreach (array_values($branchIds) as $i => $id) {
            $placeholders[] = ':b' . $i;
            $params['b' . $i] = (int) $id;
        }
        $in = $placeholders === [] ? 'NULL' : implode(',', $placeholders);

        return [
            'EXISTS (SELECT 1 FROM appointments ax
                      WHERE ax.tenant_id = c.tenant_id AND ax.customer_id = c.id
                        AND ax.branch_id IN (' . $in . '))',
            $params,
        ];
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

    /**
     * First active customer matching phone (e164) or lowercased email — the
     * pre-create duplicate check. Both are optional; pass what was provided.
     * @return array<string, mixed>|null
     */
    public function findActiveByPhoneOrEmail(int $tenantId, ?string $phone, ?string $email, ?int $excludeId = null): ?array
    {
        $or = [];
        $params = ['t' => $tenantId];
        if ($phone !== null && $phone !== '') {
            $or[] = 'phone_e164 = :p';
            $params['p'] = $phone;
        }
        if ($email !== null && $email !== '') {
            $or[] = 'LOWER(email) = :e';
            $params['e'] = strtolower($email);
        }
        if ($or === []) {
            return null;
        }
        $sql = "SELECT * FROM customers WHERE tenant_id = :t AND status = 'active'"
            . ($excludeId !== null ? ' AND id != :x' : '')
            . ' AND (' . implode(' OR ', $or) . ') LIMIT 1';
        if ($excludeId !== null) {
            $params['x'] = $excludeId;
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Rebuild this customer's match keys from its current contact fields.
     * Without this, customers created/edited via the directory stay invisible
     * to the booking flow's phone dedup and silently duplicate. A 1062 means
     * another customer owns the key — the caller's dup check already covers
     * that UX, so a stray race is skipped, not stolen.
     */
    public function syncMatchKeys(int $tenantId, int $customerId, ?string $phone, ?string $email): void
    {
        $this->db->pdo()->prepare(
            'DELETE FROM customer_match_keys WHERE tenant_id = :t AND customer_id = :c',
        )->execute(['t' => $tenantId, 'c' => $customerId]);

        foreach (['phone' => $phone, 'email' => $email !== null && $email !== '' ? strtolower($email) : null] as $type => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $this->db->pdo()->prepare(
                    'INSERT INTO customer_match_keys (tenant_id, match_type, match_value, customer_id)
                     VALUES (:t, :ty, :v, :c)',
                )->execute(['t' => $tenantId, 'ty' => $type, 'v' => $value, 'c' => $customerId]);
            } catch (\PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Groups of active customers sharing the same phone or email — candidate
     * duplicates for the merge UI.
     * @return list<array{match_type: string, key: string, customers: list<array<string, mixed>>}>
     */
    public function duplicateGroups(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT match_type, dup_key FROM (
                SELECT 'phone' AS match_type, phone_e164 AS dup_key
                  FROM customers
                 WHERE tenant_id = :t1 AND status = 'active'
                   AND phone_e164 IS NOT NULL AND phone_e164 <> ''
                UNION ALL
                SELECT 'email', LOWER(email)
                  FROM customers
                 WHERE tenant_id = :t2 AND status = 'active'
                   AND email IS NOT NULL AND email <> ''
             ) d
             GROUP BY match_type, dup_key HAVING COUNT(*) > 1
             ORDER BY match_type, dup_key",
        );
        $stmt->execute(['t1' => $tenantId, 't2' => $tenantId]);
        $groups = $stmt->fetchAll();

        $out = [];
        foreach ($groups as $g) {
            $isPhone = $g['match_type'] === 'phone';
            $stmt2 = $this->db->pdo()->prepare(sprintf(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM appointments a
                          WHERE a.tenant_id = c.tenant_id AND a.customer_id = c.id) AS appointment_count
                   FROM customers c
                  WHERE c.tenant_id = :t AND c.status = 'active' AND %s = :k
                  ORDER BY c.created_at",
                $isPhone ? 'c.phone_e164' : 'LOWER(c.email)',
            ));
            $stmt2->execute(['t' => $tenantId, 'k' => (string) $g['dup_key']]);
            $customers = array_values($stmt2->fetchAll());
            if (count($customers) >= 2) {
                $out[] = [
                    'match_type' => (string) $g['match_type'],
                    'key' => (string) $g['dup_key'],
                    'customers' => $customers,
                ];
            }
        }

        return $out;
    }

    /**
     * Merge $loserId into $winnerId: repoint every customer FK (appointments,
     * payments, consents), transfer match keys the winner lacks, backfill the
     * winner's empty contact fields, then mark the loser merged.
     */
    public function merge(int $tenantId, int $loserId, int $winnerId): void
    {
        $this->db->transaction(function () use ($tenantId, $loserId, $winnerId): void {
            $pdo = $this->db->pdo();
            foreach (['appointments', 'payments', 'customer_consents'] as $table) {
                $pdo->prepare("UPDATE {$table} SET customer_id = :w WHERE tenant_id = :t AND customer_id = :l")
                    ->execute(['w' => $winnerId, 't' => $tenantId, 'l' => $loserId]);
            }

            // Match keys the winner already owns would collide on the PK —
            // drop those losers' copies, move the rest over.
            $pdo->prepare(
                "DELETE mk FROM customer_match_keys mk
                  JOIN customer_match_keys wk
                    ON wk.tenant_id = mk.tenant_id AND wk.match_type = mk.match_type
                       AND wk.match_value = mk.match_value AND wk.customer_id = :w
                 WHERE mk.tenant_id = :t AND mk.customer_id = :l",
            )->execute(['w' => $winnerId, 't' => $tenantId, 'l' => $loserId]);
            $pdo->prepare('UPDATE customer_match_keys SET customer_id = :w WHERE tenant_id = :t AND customer_id = :l')
                ->execute(['w' => $winnerId, 't' => $tenantId, 'l' => $loserId]);

            // Backfill the winner's empty contact fields from the loser.
            $byId = [];
            $full = $pdo->prepare('SELECT id, phone_e164, email, preferred_staff_id, preferred_service_id, notes FROM customers WHERE tenant_id = :t AND id IN (:l, :w)');
            $full->execute(['t' => $tenantId, 'l' => $loserId, 'w' => $winnerId]);
            foreach ($full as $f) {
                $byId[(int) $f['id']] = $f;
            }
            $w = $byId[$winnerId] ?? [];
            $l = $byId[$loserId] ?? [];
            $fill = [];
            foreach (['phone_e164', 'email', 'preferred_staff_id', 'preferred_service_id', 'notes'] as $col) {
                if (($w[$col] ?? null) === null && ($l[$col] ?? null) !== null) {
                    $fill[$col] = $l[$col];
                }
            }
            if ($fill !== []) {
                $this->update($tenantId, $winnerId, $fill);
            }

            $pdo->prepare(
                "UPDATE customers SET status = 'merged', merged_into_customer_id = :w
                  WHERE tenant_id = :t AND id = :l",
            )->execute(['w' => $winnerId, 't' => $tenantId, 'l' => $loserId]);
        });
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
