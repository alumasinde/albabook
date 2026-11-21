<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Roles are tenant-owned; tenant_id IS NULL rows are platform-owned templates
 * copied into a tenant at registration (decisions.md D-007).
 */
final class RoleRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    /** @return list<array<string, mixed>> */
    public function templates(): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id, name, slug, description, is_system FROM roles WHERE tenant_id IS NULL ORDER BY id');
        $stmt->execute();

        return array_values($stmt->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, name, slug, description, is_system FROM roles WHERE tenant_id = :tenant_id ORDER BY is_system DESC, name',
        );
        $stmt->execute(['tenant_id' => $tenantId]);

        return array_values($stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function findForTenantBySlug(int $tenantId, string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, uuid, name, slug FROM roles WHERE tenant_id = :tenant_id AND slug = :slug LIMIT 1',
        );
        $stmt->execute(['tenant_id' => $tenantId, 'slug' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Copy every template into a new tenant. Call inside the registration
     * transaction.
     *
     * @return array<string, int> template slug => new role id
     */
    public function copyTemplatesForTenant(int $tenantId): array
    {
        $pdo = $this->db->pdo();
        $created = [];

        $insertRole = $pdo->prepare(
            'INSERT INTO roles (uuid, tenant_id, name, slug, description, is_system)
             VALUES (:uuid, :tenant_id, :name, :slug, :description, :is_system)',
        );
        $copyPermissions = $pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_scope, permission_id)
             SELECT :new_role_id, \'tenant\', permission_id FROM role_permissions WHERE role_id = :template_id',
        );

        foreach ($this->templates() as $template) {
            $insertRole->execute([
                'uuid' => $this->uuids->generate(),
                'tenant_id' => $tenantId,
                'name' => $template['name'],
                'slug' => $template['slug'],
                'description' => $template['description'],
                'is_system' => (int) $template['is_system'],
            ]);
            $roleId = (int) $pdo->lastInsertId();
            $copyPermissions->execute(['new_role_id' => $roleId, 'template_id' => (int) $template['id']]);
            $created[(string) $template['slug']] = $roleId;
        }

        return $created;
    }

    public function assignRole(int $tenantId, int $tenantUserId, int $roleId): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO membership_roles (tenant_id, tenant_user_id, role_id)
             VALUES (:tenant_id, :tenant_user_id, :role_id)
             ON DUPLICATE KEY UPDATE role_id = role_id',
        )->execute(['tenant_id' => $tenantId, 'tenant_user_id' => $tenantUserId, 'role_id' => $roleId]);
    }

    /** @return list<string> */
    public function permissionIdentifiersForMembership(int $tenantId, int $tenantUserId): array
    {
        $sql = 'SELECT DISTINCT p.identifier
                FROM membership_roles mr
                INNER JOIN role_permissions rp ON rp.role_id = mr.role_id
                INNER JOIN permissions p ON p.id = rp.permission_id AND p.scope = \'tenant\'
                WHERE mr.tenant_id = :tenant_id AND mr.tenant_user_id = :tenant_user_id
                ORDER BY p.identifier';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'tenant_user_id' => $tenantUserId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<array{slug: string, name: string}> */
    public function roleSummariesForMembership(int $tenantId, int $tenantUserId): array
    {
        $sql = 'SELECT r.slug, r.name
                FROM membership_roles mr
                INNER JOIN roles r ON r.id = mr.role_id AND r.tenant_id = mr.tenant_id
                WHERE mr.tenant_id = :tenant_id AND mr.tenant_user_id = :tenant_user_id
                ORDER BY r.name';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['tenant_id' => $tenantId, 'tenant_user_id' => $tenantUserId]);

        return array_values($stmt->fetchAll());
    }
}
