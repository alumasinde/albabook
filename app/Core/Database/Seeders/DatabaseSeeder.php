<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Core\Database\Database;

/**
 * Ordered reference data. Runs inside one transaction so a failed seed never
 * leaves a half-populated catalogue.
 */
final class DatabaseSeeder
{
    public function __construct(
        private readonly Database $db,
        private readonly PermissionSeeder $permissions,
        private readonly RoleTemplateSeeder $roles,
        private readonly TenantStatusPolicySeeder $policies,
    ) {}

    /** @return array{permissions: int, roles: int, policies: int} */
    public function run(): array
    {
        return $this->db->transaction(fn(): array => [
            'permissions' => $this->permissions->run(),
            'roles' => $this->roles->run(),
            'policies' => $this->policies->run(),
        ]);
    }
}
