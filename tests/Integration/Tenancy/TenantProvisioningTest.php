<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Modules\Auth\TenantPermission;
use App\Tenancy\TenantSettingsService;
use Tests\Integration\Support\Phase2TestCase;

final class TenantProvisioningTest extends Phase2TestCase
{
    public function testProvisioningCopiesRolesAndSeatsTheOwner(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $slug = $this->uniqueSlug('mrembo');
        $created = $this->provision($ownerId, $slug, 'Mrembo Salon');

        $tenant = $this->tenants->findById($created['tenant_id']);
        self::assertNotNull($tenant);
        self::assertSame('Mrembo Salon', $tenant['name']);
        self::assertSame($slug, $tenant['slug']);
        self::assertSame('active', $tenant['status']);
        self::assertSame('Africa/Nairobi', $tenant['timezone']);
        self::assertSame('KES', $tenant['currency']);

        $slugs = array_column($this->roles->forTenant($created['tenant_id']), 'slug');
        sort($slugs);
        self::assertSame(
            ['accountant', 'branch_manager', 'business_administrator', 'owner', 'receptionist', 'staff'],
            $slugs,
        );

        $membership = $this->memberships->findActive($ownerId, $created['tenant_id']);
        self::assertNotNull($membership, 'the owner joins as an active member');

        $ownerRoleIds = array_column($this->roles->roleSummariesForMembership($created['tenant_id'], (int) $membership['id']), 'slug');
        self::assertSame(['owner'], $ownerRoleIds);

        $permissions = $this->roles->permissionIdentifiersForMembership($created['tenant_id'], (int) $membership['id']);
        self::assertCount(count(TenantPermission::cases()), $permissions);
        self::assertContains('roles.manage', $permissions);
        self::assertContains('billing.view', $permissions);
    }

    public function testProvisioningCreatesTheSettingsRow(): void
    {
        $created = $this->provision($this->createUser($this->uniqueEmail('owner')));

        $settings = (new TenantSettingsService($this->db))->find($created['tenant_id']);

        self::assertSame($created['tenant_id'], (int) $settings['tenant_id']);
        self::assertSame(1, (int) $settings['support_write_enabled']);
    }

    public function testProvisioningRefusesWhenRoleTemplatesAreMissing(): void
    {
        $this->pdo->exec('DELETE FROM role_permissions');
        $this->pdo->exec('DELETE FROM roles WHERE tenant_id IS NULL');
        $ownerId = $this->createUser($this->uniqueEmail('owner'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db:seed');

        $this->provision($ownerId);
    }
}
