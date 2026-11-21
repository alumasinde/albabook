<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;
use App\Shared\Time\Clock;

/**
 * Membership, roles and branch scope are always read per (tenant, user);
 * nothing here trusts a tenant id without that pair (multi-tenancy.md §4, §8).
 */
final class TenantMembershipRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed>|null */
    public function findActive(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tenant_id, user_id, status, joined_at FROM tenant_users
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND status = \'active\' LIMIT 1',
        );
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed>|null any status */
    public function find(int $userId, int $tenantId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, tenant_id, user_id, status, joined_at FROM tenant_users
             WHERE user_id = :user_id AND tenant_id = :tenant_id LIMIT 1',
        );
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

        return $stmt->fetch() ?: null;
    }

    public function create(int $tenantId, int $userId, string $status = 'invited', ?int $invitedByUserId = null): int
    {
        $pdo = $this->db->pdo();
        $pdo->prepare(
            'INSERT INTO tenant_users (tenant_id, user_id, status, invited_by_user_id, joined_at)
             VALUES (:tenant_id, :user_id, :status, :invited_by, :joined_at)',
        )->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'status' => $status,
            'invited_by' => $invitedByUserId,
            'joined_at' => $status === 'active' ? $this->clock->now()->format('Y-m-d H:i:s') : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function activate(int $tenantUserId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE tenant_users SET status = \'active\', joined_at = COALESCE(joined_at, :now) WHERE id = :id',
        )->execute(['now' => $this->clock->now()->format('Y-m-d H:i:s'), 'id' => $tenantUserId]);
    }

    /**
     * Active memberships for the workspace picker.
     *
     * @return list<array<string, mixed>>
     */
    public function activeForUser(int $userId): array
    {
        $sql = 'SELECT tu.id AS tenant_user_id, tu.status AS membership_status, tu.joined_at,
                       t.id AS tenant_id, t.uuid AS tenant_uuid, t.name, t.slug, t.status AS tenant_status
                FROM tenant_users tu
                INNER JOIN tenants t ON t.id = tu.tenant_id
                WHERE tu.user_id = :user_id AND tu.status = \'active\'
                ORDER BY t.name';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return array_values($stmt->fetchAll());
    }

    public function countActiveForUser(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM tenant_users WHERE user_id = :user_id AND status = \'active\'',
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Team page roster with the role names each member holds.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $sql = 'SELECT tu.id AS tenant_user_id, tu.status, tu.joined_at,
                       u.uuid AS user_uuid, u.first_name, u.middle_name, u.last_name, u.email,
                       GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR ", ") AS roles
                FROM tenant_users tu
                INNER JOIN users u ON u.id = tu.user_id
                LEFT JOIN membership_roles mr ON mr.tenant_id = tu.tenant_id AND mr.tenant_user_id = tu.id
                LEFT JOIN roles r ON r.id = mr.role_id
                WHERE tu.tenant_id = :tenant_id
                GROUP BY tu.id, tu.status, tu.joined_at, u.uuid, u.first_name, u.middle_name, u.last_name, u.email
                ORDER BY tu.status = \'active\' DESC, u.first_name, u.last_name';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /**
     * Empty means unrestricted branch scope (decisions.md D-007).
     *
     * @return list<int>
     */
    public function branchIds(int $tenantId, int $tenantUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT branch_id FROM membership_branches WHERE tenant_id = :tenant_id AND tenant_user_id = :tenant_user_id',
        );
        $stmt->execute(['tenant_id' => $tenantId, 'tenant_user_id' => $tenantUserId]);

        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }
}
