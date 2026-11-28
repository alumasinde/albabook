<?php

declare(strict_types=1);

namespace Tests\Integration\Tenancy;

use App\Core\Http\Request;
use App\Modules\Auth\Context\AuthContext;
use App\Modules\Auth\Context\AuthenticatedUser;
use App\Modules\Auth\Context\MembershipContext;
use App\Modules\Booking\Repositories\BookingEditorRepository;
use App\Modules\Branches\Repositories\BranchRepository;
use App\Modules\Services\Repositories\ServiceRepository;
use App\Modules\Settings\Repositories\BookingPolicyRepository;
use App\Modules\Settings\Support\BookingRules;
use App\Modules\Staff\Repositories\StaffRepository;
use App\Modules\Tenants\Controllers\SetupWizardApiController;
use App\Modules\Tenants\Services\SetupReadiness;
use App\Shared\Http\AppUrl;
use App\Tenancy\TenantContext;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Guided setup wizard (update.md 2.2): GET /api/setup must report the same
 * readiness that gates go-live (2.4), prefill what is already saved, and
 * reflect each saved step so the wizard resumes where the owner stopped.
 */
final class SetupWizardStateTest extends Phase2TestCase
{
    private BranchRepository $branches;
    private ServiceRepository $services;
    private StaffRepository $staff;
    private BookingPolicyRepository $policies;
    private BookingEditorRepository $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branches = new BranchRepository($this->db);
        $this->services = new ServiceRepository($this->db);
        $this->staff = new StaffRepository($this->db);
        $this->policies = new BookingPolicyRepository($this->db);
        $this->editor = new BookingEditorRepository($this->db);
    }

    public function testFreshTenantReportsEveryBlockingStepMissing(): void
    {
        $userId = $this->createUser($this->uniqueEmail());
        $slug = $this->uniqueSlug();
        $provisioned = $this->provision($userId, $slug);

        $data = $this->call($userId, $provisioned, $slug);

        self::assertFalse($data['readiness']['ready']);
        self::assertSame([], $data['branches']);
        self::assertSame([], $data['services']);
        self::assertSame([], $data['staff']);
        self::assertFalse($data['rules_saved']);
        self::assertFalse($data['page']['is_enabled']);
        self::assertSame(BookingRules::DEFAULTS, $data['rules']);
        self::assertStringEndsWith('/' . $slug, $data['booking_url']);
        self::assertSame($provisioned['tenant_user_id'], $data['me']['tenant_user_id']);
        self::assertTrue($data['me']['email_verified']);
        self::assertTrue($data['can']['publish']);

        $done = array_column($data['readiness']['items'], 'done', 'key');
        self::assertTrue($done['email']);
        foreach (['branch', 'hours', 'service', 'staff'] as $blocking) {
            self::assertFalse($done[$blocking], "{$blocking} should be missing on a fresh tenant");
        }
    }

    public function testCompletedStepsMakeTheTenantReady(): void
    {
        $userId = $this->createUser($this->uniqueEmail());
        $slug = $this->uniqueSlug();
        $provisioned = $this->provision($userId, $slug);
        $tenantId = $provisioned['tenant_id'];

        $branchId = $this->branches->insert([
            'uuid' => $this->uuids->generate(),
            'tenant_id' => $tenantId,
            'name' => 'Main branch',
            'address_line1' => null,
            'address_line2' => null,
            'county' => 'Nairobi',
            'town' => 'Westlands',
            'phone' => null,
            'email' => null,
            'timezone' => 'Africa/Nairobi',
            'is_active' => 1,
        ]);
        $this->branches->syncBusinessHours($tenantId, $branchId, [
            ['weekday' => 1, 'opens_at' => '08:00:00', 'closes_at' => '18:00:00'],
        ]);

        $serviceId = $this->services->insert([
            'uuid' => $this->uuids->generate(),
            'tenant_id' => $tenantId,
            'service_category_id' => null,
            'name' => 'Hair retouch',
            'description' => null,
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'price_type' => 'fixed',
            'price_amount_minor' => 150000,
            'min_amount_minor' => null,
            'max_amount_minor' => null,
            'currency' => 'KES',
            'is_active' => 1,
            'is_bookable_online' => 1,
            'sort_position' => 0,
        ]);
        $this->services->syncBranches($tenantId, $serviceId, [$branchId]);

        $staffId = $this->staff->insert([
            'uuid' => $this->uuids->generate(),
            'tenant_id' => $tenantId,
            'tenant_user_id' => $provisioned['tenant_user_id'],
            'first_name' => 'Test',
            'last_name' => 'Person',
            'display_name' => 'Test Person',
            'email' => null,
            'phone_e164' => null,
            'bio' => null,
            'booking_priority' => 0,
            'is_bookable_online' => 1,
            'status' => 'active',
        ]);
        $this->staff->syncBranches($tenantId, $staffId, [$branchId]);
        $this->staff->syncServices($tenantId, $staffId, [$serviceId]);
        $this->staff->syncSchedules($tenantId, $staffId, [$branchId], [
            ['weekday' => 1, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00'],
        ]);

        $this->policies->saveTenantRules($tenantId, BookingRules::DEFAULTS);
        $this->editor->settings($tenantId); // ensure the settings row exists
        $this->pdo->prepare('UPDATE booking_page_settings SET is_enabled = 1 WHERE tenant_id = :t')
            ->execute(['t' => $tenantId]);

        $data = $this->call($userId, $provisioned, $slug);

        self::assertTrue($data['readiness']['ready']);
        self::assertSame([], array_values(array_filter(
            $data['readiness']['items'],
            static fn(array $item): bool => $item['blocking'] && !$item['done'],
        )));

        self::assertSame($branchId, $data['branches'][0]['id']);
        self::assertSame([['weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00']], $data['branches'][0]['hours']);
        self::assertSame($serviceId, $data['services'][0]['id']);
        self::assertSame(150000, $data['services'][0]['price_amount_minor']);
        self::assertSame([$branchId], $data['services'][0]['branch_ids']);
        self::assertSame($staffId, $data['staff'][0]['id']);
        self::assertSame([$branchId], $data['staff'][0]['branch_ids']);
        self::assertSame([$serviceId], $data['staff'][0]['service_ids']);
        self::assertSame([['weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00']], $data['staff'][0]['schedule']);
        self::assertTrue($data['rules_saved']);
        self::assertTrue($data['page']['is_enabled']);
    }

    public function testUnverifiedOwnerEmailKeepsTheGateClosed(): void
    {
        $userId = $this->createUser($this->uniqueEmail(), verified: false);
        $slug = $this->uniqueSlug();
        $provisioned = $this->provision($userId, $slug);

        $data = $this->call($userId, $provisioned, $slug, verified: false);

        self::assertFalse($data['me']['email_verified']);
        $done = array_column($data['readiness']['items'], 'done', 'key');
        self::assertFalse($done['email']);
        self::assertFalse($data['readiness']['ready']);
    }

    /** @param array{tenant_id: int, tenant_uuid: string, tenant_user_id: int} $provisioned
     *  @return array<string, mixed> */
    private function call(int $userId, array $provisioned, string $slug, bool $verified = true): array
    {
        $auth = new AuthContext();
        $auth->user = new AuthenticatedUser(
            id: $userId,
            uuid: $this->uuids->generate(),
            email: 'owner@example.test',
            firstName: 'Test',
            lastName: 'Person',
            emailVerified: $verified,
            locale: 'en',
            sessionId: 0,
            activeTenantId: $provisioned['tenant_id'],
            mfaVerified: false,
            sessionToken: '',
        );

        $controller = new SetupWizardApiController(
            $this->branches,
            $this->services,
            $this->staff,
            $this->policies,
            $this->editor,
            new SetupReadiness($this->db),
            $auth,
            new AppUrl($this->config),
        );

        $tenant = new TenantContext(
            tenantId: $provisioned['tenant_id'],
            uuid: $provisioned['tenant_uuid'],
            name: 'Test Salon',
            slug: $slug,
            timezone: 'Africa/Nairobi',
            currency: 'KES',
            status: 'active',
        );
        $membership = new MembershipContext(
            tenantUserId: $provisioned['tenant_user_id'],
            userId: $userId,
            tenantId: $provisioned['tenant_id'],
            roleSlugs: ['owner'],
            permissions: [
                'settings.view', 'settings.update',
                'branches.manage', 'services.manage', 'staff.manage',
                'booking_page.manage', 'booking_page.publish',
            ],
            branchIds: null,
        );

        $request = Request::create('GET', 'http://app.albabook.test/api/setup')
            ->withAttribute('tenant', $tenant)
            ->withAttribute('membership', $membership);

        $response = $controller->state($request, []);
        /** @var array{ok: bool, data: array<string, mixed>} $payload */
        $payload = json_decode($response->body(), true);
        self::assertTrue($payload['ok'] ?? false, $response->body());

        return $payload['data'];
    }
}
