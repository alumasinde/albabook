<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Creates a tenant, its settings, default roles and the owner membership.
 * Must run inside the caller's transaction so a half-provisioned tenant is
 * never visible (registration wraps user + tenant + membership).
 */
final class TenantProvisioningService
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantRepository $tenants,
        private readonly RoleRepository $roles,
        private readonly TenantMembershipRepository $memberships,
        private readonly UuidFactory $uuids,
    ) {}

    /**
     * @param array{name: string, slug: string, timezone: string, currency: string, default_locale: string} $data
     * @return array{tenant_id: int, tenant_uuid: string, tenant_user_id: int, roles: array<string, int>}
     */
    public function provision(array $data, int $ownerUserId, string $status = 'pending'): array
    {
        $uuid = $this->uuids->generate();
        $tenantId = $this->tenants->create($data + [
            'uuid' => $uuid,
            'status' => $status,
        ]);

        $this->db->pdo()->prepare('INSERT INTO tenant_settings (tenant_id) VALUES (:tenant_id)')
            ->execute(['tenant_id' => $tenantId]);

        $roles = $this->roles->copyTemplatesForTenant($tenantId);
        if (!isset($roles['owner'])) {
            throw new \RuntimeException('Default role templates are missing. Run `php bin/console db:seed` before registering tenants.');
        }

        $tenantUserId = $this->memberships->create($tenantId, $ownerUserId, 'active');
        $this->roles->assignRole($tenantId, $tenantUserId, $roles['owner']);

        return [
            'tenant_id' => $tenantId,
            'tenant_uuid' => $uuid,
            'tenant_user_id' => $tenantUserId,
            'roles' => $roles,
        ];
    }
}
