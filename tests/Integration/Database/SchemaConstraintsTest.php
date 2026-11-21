<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use Tests\Support\DatabaseTestCase;

/**
 * The database itself must reject cross-tenant references, unknown codes and
 * invalid shapes (decisions.md D-007, D-020, D-021, D-023, D-024).
 * Every test runs in a rolled-back transaction.
 */
final class SchemaConstraintsTest extends DatabaseTestCase
{
    private int $tenantA;
    private int $tenantB;
    private int $branchA1;
    private int $branchA2;
    private int $branchB1;
    private int $staffA;
    private int $serviceA1;
    private int $serviceA2;
    private int $variantA1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantA = $this->insert('tenants', ['uuid' => self::uuid(), 'name' => 'Royal Beauty', 'slug' => 'royal-beauty', 'status' => 'active']);
        $this->tenantB = $this->insert('tenants', ['uuid' => self::uuid(), 'name' => 'Beauty Studio', 'slug' => 'beauty-studio', 'status' => 'trial']);
        $this->branchA1 = $this->insert('branches', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'name' => 'Westlands']);
        $this->branchA2 = $this->insert('branches', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'name' => 'CBD']);
        $this->branchB1 = $this->insert('branches', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantB, 'name' => 'Kilimani']);
        $this->serviceA1 = $this->service($this->tenantA, 'Cut');
        $this->serviceA2 = $this->service($this->tenantA, 'Nails');
        $this->variantA1 = $this->insert('service_variants', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'service_id' => $this->serviceA1, 'name' => 'Long', 'duration_minutes' => 60, 'price_amount_minor' => 150000]);
        $this->staffA = $this->insert('staff', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'first_name' => 'Sarah', 'last_name' => 'K', 'display_name' => 'Sarah']);
        $this->insert('staff_branches', ['tenant_id' => $this->tenantA, 'staff_id' => $this->staffA, 'branch_id' => $this->branchA1]);
    }

    public function testUnknownLookupCodesAreRejected(): void
    {
        $this->assertRejected("INSERT INTO tenants (uuid, name, slug, status) VALUES (UUID(), 'C', 'tenant-c', 'paused')", [], 'fk_tenants_status');
        $this->assertRejected("INSERT INTO services (uuid, tenant_id, name, duration_minutes, price_type, price_amount_minor, currency) VALUES (UUID(), ?, 'X', 30, 'fixed', 1, 'XXX')", [$this->tenantA], 'fk_services_currency');
        $this->assertRejected("INSERT INTO notification_preferences (tenant_id, channel, event_key) VALUES (?, 'sms', 'booking_confrimed')", [$this->tenantA], 'fk_notification_preferences_event');
        $this->assertRejected("INSERT INTO booking_page_sections (tenant_id, section_key) VALUES (?, 'blog')", [$this->tenantA], 'fk_booking_page_sections_type');
        $this->assertRejected("INSERT INTO tenant_feature_overrides (tenant_id, feature_key) VALUES (?, 'api_acess')", [$this->tenantA], 'fk_tenant_feature_overrides_feature');
    }

    public function testSlugFormatIsEnforced(): void
    {
        $this->assertRejected("INSERT INTO tenants (uuid, name, slug) VALUES (UUID(), 'Bad', 'Bad_Slug')");
        $this->assertRejected("INSERT INTO tenants (uuid, name, slug) VALUES (UUID(), 'Bad', '-edge')");
    }

    public function testCrossTenantReferencesAreRejected(): void
    {
        // Tenant A's staff assigned to tenant B's branch.
        $this->assertRejected('INSERT INTO staff_branches (tenant_id, staff_id, branch_id) VALUES (?, ?, ?)', [$this->tenantA, $this->staffA, $this->branchB1]);
        // Tenant B claiming tenant A's branch through its own tenant_id.
        $this->assertRejected('INSERT INTO business_hours (tenant_id, branch_id, weekday, opens_at, closes_at) VALUES (?, ?, 1, "09:00", "17:00")', [$this->tenantB, $this->branchA1]);
    }

    public function testShiftsOnlyAtAssignedBranches(): void
    {
        $this->assertRejected('INSERT INTO staff_schedules (tenant_id, staff_id, branch_id, weekday, starts_at, ends_at) VALUES (?, ?, ?, 1, "09:00", "17:00")', [$this->tenantA, $this->staffA, $this->branchA2], 'fk_staff_schedules_assignment');

        $this->insert('staff_schedules', ['tenant_id' => $this->tenantA, 'staff_id' => $this->staffA, 'branch_id' => $this->branchA1, 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '17:00']);
        $this->addToAssertionCount(1);
    }

    public function testVariantMustBelongToTheSameService(): void
    {
        $type = $this->insert('resource_types', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'name' => 'Chair']);

        $this->assertRejected('INSERT INTO service_resource_requirements (tenant_id, service_id, variant_id, resource_type_id) VALUES (?, ?, ?, ?)', [$this->tenantA, $this->serviceA2, $this->variantA1, $type], 'fk_service_requirements_variant');
    }

    public function testPermissionScopesCannotCross(): void
    {
        $tenantPermission = $this->insert('permissions', ['scope' => 'tenant', 'identifier' => 'appointments.view', 'name' => 'View appointments']);
        $platformPermission = $this->insert('permissions', ['scope' => 'platform', 'identifier' => 'platform.audit.view', 'name' => 'View audit']);
        $role = $this->insert('roles', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'name' => 'Owner', 'slug' => 'owner']);
        $admin = $this->insert('platform_users', ['uuid' => self::uuid(), 'first_name' => 'A', 'last_name' => 'B', 'email' => 'ops@albatech.test', 'password_hash' => 'x']);

        $this->insert('role_permissions', ['role_id' => $role, 'permission_id' => $tenantPermission]);
        $this->assertRejected('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$role, $platformPermission]);
        $this->assertRejected("INSERT INTO role_permissions (role_id, permission_scope, permission_id) VALUES (?, 'platform', ?)", [$role, $platformPermission]);
        $this->assertRejected('INSERT INTO platform_user_permissions (platform_user_id, permission_id) VALUES (?, ?)', [$admin, $tenantPermission]);
    }

    public function testBookingPolicyInheritanceShape(): void
    {
        $this->insert('booking_policies', ['tenant_id' => $this->tenantA, 'slot_interval_minutes' => 15]);
        $this->insert('booking_policies', ['tenant_id' => $this->tenantA, 'branch_id' => $this->branchA1]);

        $this->assertRejected('INSERT INTO booking_policies (tenant_id) VALUES (?)', [$this->tenantA], 'uq_booking_policies_scope');
        $this->assertRejected("INSERT INTO booking_policies (tenant_id, branch_id, deposit_mode) VALUES (?, ?, 'fixed')", [$this->tenantA, $this->branchA2]);
        $this->assertRejected("INSERT INTO booking_policies (tenant_id, branch_id, deposit_mode, deposit_value) VALUES (?, ?, 'percent', 20000)", [$this->tenantA, $this->branchA2]);
    }

    public function testDuplicateMpesaCodeIsCaughtAcrossManualAndCallbackPaths(): void
    {
        $this->insert('payments', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'branch_id' => $this->branchA1, 'method' => 'mpesa', 'provider_reference' => 'QWE123ABC', 'amount_minor' => 100000, 'currency' => 'KES', 'status' => 'succeeded']);

        $this->assertRejected(
            "INSERT INTO payments (uuid, tenant_id, branch_id, method, provider, provider_reference, amount_minor, currency, status) VALUES (UUID(), ?, ?, 'mpesa', 'mpesa_daraja', 'QWE123ABC', 100000, 'KES', 'succeeded')",
            [$this->tenantA, $this->branchA1],
            'uq_payments_method_reference',
        );
    }

    public function testAppointmentSnapshotShapeAndStatusIntegrity(): void
    {
        $customer = $this->insert('customers', ['uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'first_name' => 'Jane', 'last_name' => 'W']);
        $appointment = $this->insert('appointments', [
            'uuid' => self::uuid(), 'tenant_id' => $this->tenantA, 'branch_id' => $this->branchA1, 'customer_id' => $customer,
            'reference' => 'ABCD2345', 'booking_source' => 'dashboard', 'start_at' => '2026-12-01 06:00:00', 'end_at' => '2026-12-01 07:00:00',
            'customer_name_snapshot' => 'Jane W', 'currency' => 'KES', 'policy_version' => 1,
        ]);

        $this->assertRejected(
            "INSERT INTO appointment_items (tenant_id, appointment_id, item_position, service_id, staff_id, service_name_snapshot, price_type, unit_amount_minor, max_amount_minor, duration_minutes, start_at, end_at, occupied_start_at, occupied_end_at, currency, policy_version)
             VALUES (?, ?, 1, ?, ?, 'Cut', 'range', 0, 5000, 30, '2026-12-01 06:00:00', '2026-12-01 06:30:00', '2026-12-01 06:00:00', '2026-12-01 06:30:00', 'KES', 1)",
            [$this->tenantA, $appointment, $this->serviceA1, $this->staffA],
            'chk_appointment_items_price_shape',
        );
        $this->assertRejected("INSERT INTO appointment_events (tenant_id, appointment_id, event_type, to_status) VALUES (?, ?, 'status_changed', 'confirmd')", [$this->tenantA, $appointment], 'fk_appointment_events_to_status');
        $this->assertRejected("INSERT INTO appointments (uuid, tenant_id, branch_id, reference, booking_source, start_at, end_at, customer_name_snapshot, currency, policy_version) VALUES (UUID(), ?, ?, 'ABCD2345', 'dashboard', '2026-12-02 06:00:00', '2026-12-02 07:00:00', 'X', 'KES', 1)", [$this->tenantA, $this->branchA1], 'uq_appointments_tenant_reference');
    }

    public function testTenantsCannotBeDeletedAndAuditIsAppendOnly(): void
    {
        $this->assertRejected('DELETE FROM tenants WHERE id = ?', [$this->tenantA]);

        $this->insert('audit_logs', ['tenant_id' => $this->tenantA, 'action' => 'test.created']);
        $this->assertRejected("UPDATE audit_logs SET action = 'tampered'", [], 'append-only');
        $this->assertRejected('DELETE FROM audit_logs', [], 'append-only');
    }

    private function service(int $tenant, string $name): int
    {
        return $this->insert('services', ['uuid' => self::uuid(), 'tenant_id' => $tenant, 'name' => $name, 'duration_minutes' => 30, 'price_type' => 'fixed', 'price_amount_minor' => 100000, 'currency' => 'KES']);
    }

    private static function uuid(): string
    {
        return (new \App\Shared\Support\UuidFactory())->generate();
    }
}
