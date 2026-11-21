<?php

declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Core\Config\Config;
use App\Core\Database\Seeders\PermissionSeeder;
use App\Core\Database\Seeders\RoleTemplateSeeder;
use App\Core\Database\Seeders\TenantStatusPolicySeeder;
use App\Core\Http\Request;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\FrozenClock;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantMembershipRepository;
use App\Tenancy\TenantProvisioningService;
use App\Tenancy\TenantRepository;
use Tests\Support\DatabaseTestCase;

/**
 * Real database, real repositories, no HTTP stack. Subclasses run inside a
 * rolled-back transaction unless they exercise code that opens its own
 * transaction (Database refuses nesting).
 */
abstract class Phase2TestCase extends DatabaseTestCase
{
    protected Config $config;
    protected FrozenClock $clock;
    protected UuidFactory $uuids;
    protected UserRepository $users;
    protected TenantRepository $tenants;
    protected RoleRepository $roles;
    protected TenantMembershipRepository $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Config::loadFrom(dirname(__DIR__, 3) . '/config');
        $this->clock = new FrozenClock();
        $this->uuids = new UuidFactory();
        $this->users = new UserRepository($this->db);
        $this->tenants = new TenantRepository($this->db);
        $this->roles = new RoleRepository($this->db, $this->uuids);
        $this->memberships = new TenantMembershipRepository($this->db, $this->clock);

        // Permission and role templates are seeded reference data in production;
        // the migrations create the tables only.
        (new PermissionSeeder($this->db))->run();
        (new RoleTemplateSeeder($this->db, $this->uuids))->run();
        (new TenantStatusPolicySeeder($this->db))->run();
    }

    protected function createUser(string $email, bool $verified = true, string $status = 'active'): int
    {
        $userId = $this->users->create([
            'first_name' => 'Test',
            'middle_name' => null,
            'last_name' => 'Person',
            'email' => $email,
            'phone_e164' => null,
            'password_hash' => 'not-a-real-hash',
            'locale' => 'en',
        ], $this->uuids->generate());

        if ($verified) {
            $this->users->markEmailVerified($userId, $this->clock->now()->format('Y-m-d H:i:s'));
        }
        if ($status !== 'active') {
            $this->pdo->prepare('UPDATE users SET status = :status WHERE id = :id')
                ->execute(['status' => $status, 'id' => $userId]);
        }

        return $userId;
    }

    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(8)) . '@example.test';
    }

    protected function uniqueSlug(string $prefix = 'salon'): string
    {
        return $prefix . '-' . bin2hex(random_bytes(4));
    }

    /** @return array{tenant_id: int, tenant_uuid: string, tenant_user_id: int, roles: array<string, int>} */
    protected function provision(int $ownerUserId, ?string $slug = null, string $name = 'Test Salon', string $status = 'active'): array
    {
        return (new TenantProvisioningService($this->db, $this->tenants, $this->roles, $this->memberships, $this->uuids))
            ->provision([
                'name' => $name,
                'slug' => $slug ?? $this->uniqueSlug(),
                'timezone' => 'Africa/Nairobi',
                'currency' => 'KES',
                'default_locale' => 'en',
            ], $ownerUserId, $status);
    }

    protected function roleId(int $tenantId, string $slug): int
    {
        $role = $this->roles->findForTenantBySlug($tenantId, $slug);
        self::assertNotNull($role, "role {$slug} should exist for tenant {$tenantId}");

        return (int) $role['id'];
    }

    protected function requestWithCookie(string $cookieName, string $token): Request
    {
        return Request::create('GET', 'http://app.albabook.test/dashboard', [], [], [$cookieName => $token]);
    }
}
