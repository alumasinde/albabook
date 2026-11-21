<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Shared\Time\DateTimeService;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Resource-aware booking: a service that requires a resource type gets a
 * concrete resource allocated atomically, overlapping bookings on the same
 * resource are rejected, and none of it leaks across tenants.
 */
final class ResourceBookingTest extends Phase2TestCase
{
    // ManualBookingService opens its own transaction (D-009 staff lock) —
    // it cannot run inside the per-test wrapping transaction. Each test
    // provisions fresh tenants so leftover rows cannot cross-contaminate.
    protected bool $wrapInTransaction = false;

    private int $tenantA;
    private int $tenantB;
    private int $branchA;
    private int $staffA1;
    private int $staffA2;
    private int $serviceA;
    private int $roomA;
    private int $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerA = $this->createUser($this->uniqueEmail('ownera'));
        $ownerB = $this->createUser($this->uniqueEmail('ownerb'));
        $this->tenantA = $this->provision($ownerA)['tenant_id'];
        $this->tenantB = $this->provision($ownerB)['tenant_id'];

        $this->branchA = $this->insert('branches', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA, 'name' => 'Westlands',
        ]);
        $this->branchB = $this->insert('branches', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantB, 'name' => 'Kilimani',
        ]);
        $staff = fn(string $name): int => $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'first_name' => $name, 'last_name' => 'T', 'display_name' => $name,
        ]);
        $this->staffA1 = $staff('Amina');
        $this->staffA2 = $staff('Brian');
        $this->serviceA = $this->insert('services', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA, 'name' => 'Massage',
            'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_amount_minor' => 350000, 'currency' => 'KES',
        ]);
        $typeA = $this->insert('resource_types', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA, 'name' => 'Room',
        ]);
        $this->insert('service_resource_requirements', [
            'tenant_id' => $this->tenantA, 'service_id' => $this->serviceA,
            'resource_type_id' => $typeA, 'quantity' => 1,
        ]);
        $this->roomA = $this->insert('resources', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'branch_id' => $this->branchA, 'resource_type_id' => $typeA,
            'name' => 'Room 1', 'capacity' => 1, 'status' => 'active',
        ]);
        // A room that belongs to tenant B — must never be allocatable to A.
        $typeB = $this->insert('resource_types', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantB, 'name' => 'Room',
        ]);
        $this->insert('resources', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantB,
            'branch_id' => $this->branchB, 'resource_type_id' => $typeB,
            'name' => 'Room B', 'capacity' => 1, 'status' => 'active',
        ]);
    }

    public function testBookingAllocatesTheFreeResource(): void
    {
        $service = $this->bookingService();
        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '10:00',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
        self::assertSame('pending', $created['status']);

        $rows = $this->pdo->query(
            'SELECT resource_id FROM appointment_resources WHERE tenant_id = ' . $this->tenantA,
        )->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->roomA, (int) $rows[0]['resource_id']);
    }

    public function testOverlappingBookingOnTheSameRoomIsRejected(): void
    {
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-15', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );

        // Different staff, same room window — the resource is the blocker now.
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('No room or equipment is free');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA2, $this->serviceA, '2030-01-15', '10:30', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testResourceLookupCannotCrossTenants(): void
    {
        $repo = new AppointmentRepository($this->db);

        // Tenant B asking for a room at tenant A's branch finds nothing.
        self::assertNull($repo->freeResourceForType(
            $this->tenantB, $this->branchA, 1,
            '2030-01-14 00:00:00', '2030-01-15 00:00:00',
            2, '08:00:00', '18:00:00',
        ));
        // Tenant B cannot read tenant A's service either.
        self::assertNull($repo->serviceFor($this->tenantB, $this->serviceA, $this->branchA));
    }

    private function bookingService(): ManualBookingService
    {
        return new ManualBookingService(
            $this->db,
            new AppointmentRepository($this->db),
            new BookingPageRepository($this->db),
            new DateTimeService($this->clock),
            $this->uuids,
        );
    }
}
