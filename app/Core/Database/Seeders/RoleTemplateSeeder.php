<?php

declare(strict_types=1);

namespace App\Core\Database\Seeders;

use App\Auth\TenantPermission;
use App\Core\Database\Database;
use App\Shared\Support\UuidFactory;

/**
 * Platform-owned role templates (tenant_id NULL) copied into every new tenant
 * by TenantProvisioningService (plan.md §14.3). Safe to run repeatedly.
 */
final class RoleTemplateSeeder
{
    /** @var list<array{slug: string, name: string, description: string, permissions: list<string>}> */
    private const TEMPLATES = [
        [
            'slug' => 'owner',
            'name' => 'Owner',
            'description' => 'Full access to every part of the business.',
            'permissions' => [], // filled with the whole tenant catalogue at run time
        ],
        [
            'slug' => 'business_administrator',
            'name' => 'Business administrator',
            'description' => 'Runs day-to-day operations and manages the team.',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'appointments.confirm', 'appointments.reschedule', 'appointments.cancel',
                'appointments.override_policy', 'appointments.override_conflict',
                'calendar.manage_blocks',
                'customers.view', 'customers.create', 'customers.update', 'customers.delete',
                'customers.view_notes', 'customers.export',
                'payments.view', 'payments.record', 'payments.refund',
                'services.view', 'services.manage',
                'staff.view', 'staff.manage',
                'branches.view', 'branches.manage',
                'resources.view', 'resources.manage',
                'booking_page.view', 'booking_page.manage', 'booking_page.publish',
                'reports.view', 'reports.view_financial', 'reports.export',
                'notifications.view', 'notifications.manage',
                'integrations.view', 'integrations.manage',
                'settings.view', 'settings.update',
                'users.manage', 'roles.manage',
                'billing.view',
            ],
        ],
        [
            'slug' => 'branch_manager',
            'name' => 'Branch manager',
            'description' => 'Manages the branches and people assigned to them.',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'appointments.confirm', 'appointments.reschedule', 'appointments.cancel',
                'calendar.manage_blocks',
                'customers.view', 'customers.create', 'customers.update', 'customers.view_notes',
                'payments.view', 'payments.record',
                'services.view', 'staff.view', 'branches.view', 'resources.view',
                'booking_page.view', 'reports.view', 'notifications.view', 'settings.view',
            ],
        ],
        [
            'slug' => 'receptionist',
            'name' => 'Receptionist',
            'description' => 'Books and manages appointments at the front desk.',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.create', 'appointments.update',
                'appointments.confirm', 'appointments.reschedule', 'appointments.cancel',
                'customers.view', 'customers.create', 'customers.update', 'customers.view_notes',
                'payments.view', 'payments.record',
                'services.view', 'staff.view', 'branches.view', 'resources.view',
                'notifications.view',
            ],
        ],
        [
            'slug' => 'staff',
            'name' => 'Staff member',
            'description' => 'Sees their own schedule and basic customer details.',
            'permissions' => [
                'dashboard.view',
                'appointments.view', 'appointments.view_own',
                'customers.view',
                'services.view',
            ],
        ],
        [
            'slug' => 'accountant',
            'name' => 'Accountant',
            'description' => 'Reviews payments, financial reports and billing.',
            'permissions' => [
                'dashboard.view',
                'customers.view',
                'payments.view', 'payments.refund',
                'reports.view', 'reports.view_financial', 'reports.export',
                'billing.view',
            ],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UuidFactory $uuids,
    ) {}

    public function run(): int
    {
        $pdo = $this->db->pdo();

        /** @var array<string, int> $permissionIds */
        $permissionIds = [];
        $stmt = $pdo->prepare("SELECT identifier, id FROM permissions WHERE scope = 'tenant'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $permissionIds[(string) $row['identifier']] = (int) $row['id'];
        }

        $upsert = $pdo->prepare(
            'INSERT INTO roles (uuid, tenant_id, name, slug, description, is_system)
             VALUES (:uuid, NULL, :name, :slug, :description, TRUE)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = TRUE',
        );
        $find = $pdo->prepare('SELECT id FROM roles WHERE tenant_id IS NULL AND slug = :slug LIMIT 1');
        $clear = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
        $grant = $pdo->prepare(
            "INSERT INTO role_permissions (role_id, permission_scope, permission_id)
             VALUES (:role_id, 'tenant', :permission_id)",
        );

        foreach (self::TEMPLATES as $template) {
            $upsert->execute([
                'uuid' => $this->uuids->generate(),
                'name' => $template['name'],
                'slug' => $template['slug'],
                'description' => $template['description'],
            ]);

            $find->execute(['slug' => $template['slug']]);
            $roleId = (int) $find->fetchColumn();

            $permissions = $template['slug'] === 'owner'
                ? array_map(static fn(TenantPermission $permission): string => $permission->value, TenantPermission::cases())
                : $template['permissions'];

            $clear->execute(['role_id' => $roleId]);
            foreach ($permissions as $identifier) {
                if (!isset($permissionIds[$identifier])) {
                    throw new \RuntimeException("Permission \"{$identifier}\" is missing. Run the permission seeder first.");
                }
                $grant->execute(['role_id' => $roleId, 'permission_id' => $permissionIds[$identifier]]);
            }
        }

        return count(self::TEMPLATES);
    }
}
