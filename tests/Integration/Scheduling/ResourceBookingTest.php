<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Booking\Repositories\BookingHoldRepository;
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
        // The write path enforces assignment, capability and working
        // windows — provision a bookable world: both staff at branch A,
        // linked to the service, with shifts inside business hours.
        foreach ([$this->staffA1, $this->staffA2] as $staffId) {
            $this->insert('staff_branches', [
                'tenant_id' => $this->tenantA, 'staff_id' => $staffId, 'branch_id' => $this->branchA,
            ]);
            $this->insert('staff_services', [
                'tenant_id' => $this->tenantA, 'staff_id' => $staffId, 'service_id' => $this->serviceA,
            ]);
            for ($weekday = 1; $weekday <= 7; $weekday += 1) {
                $this->insert('staff_schedules', [
                    'tenant_id' => $this->tenantA, 'staff_id' => $staffId, 'branch_id' => $this->branchA,
                    'weekday' => $weekday, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00',
                ]);
            }
        }
        for ($weekday = 1; $weekday <= 7; $weekday += 1) {
            $this->insert('business_hours', [
                'tenant_id' => $this->tenantA, 'branch_id' => $this->branchA,
                'weekday' => $weekday, 'opens_at' => '08:00:00', 'closes_at' => '18:00:00',
            ]);
        }
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

    public function testDashboardBookingOutsideWorkingHoursIsRejected(): void
    {
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('outside working hours');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '19:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testOverridePolicyLetsAStaffBookingIgnoreWorkingHours(): void
    {
        $service = $this->bookingService();
        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '19:00',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'dashboard', 'en', 'KES', null, 'tenant_user',
            true,
        );
        self::assertSame('pending', $created['status']);
    }

    public function testDashboardBookingInsideAStaffBreakIsRejected(): void
    {
        // 2030-01-16 is a Wednesday (weekday 3).
        $this->insert('staff_breaks', [
            'tenant_id' => $this->tenantA, 'staff_id' => $this->staffA1,
            'weekday' => 3, 'starts_at' => '12:00:00', 'ends_at' => '13:00:00',
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('outside working hours');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-16', '12:30', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testStaffNotAssignedToTheBranchIsRejectedEvenWithOverride(): void
    {
        $float = $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'first_name' => 'Float', 'last_name' => 'T', 'display_name' => 'Float',
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('not assigned to this branch');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $float, $this->serviceA, '2030-01-14', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user', true,
        );
    }

    public function testStaffWithoutTheServiceLinkIsRejected(): void
    {
        // Assigned to the branch with a shift, but no staff_services row.
        $helper = $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'first_name' => 'Helper', 'last_name' => 'T', 'display_name' => 'Helper',
        ]);
        $this->insert('staff_branches', [
            'tenant_id' => $this->tenantA, 'staff_id' => $helper, 'branch_id' => $this->branchA,
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('does not offer this service');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $helper, $this->serviceA, '2030-01-14', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testDashboardBookingInsideMinNoticeIsRejectedUnlessOverridden(): void
    {
        // 2030-01-14 07:00 UTC = 10:00 Nairobi; a 10:30 booking is +30 min.
        $this->clock->set('2030-01-14 07:00:00');
        $this->insert('booking_policies', [
            'tenant_id' => $this->tenantA, 'branch_id' => null, 'min_notice_minutes' => 120,
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        try {
            $service->create(
                $this->tenantA, $this->branchA, 'Africa/Nairobi',
                $this->staffA1, $this->serviceA, '2030-01-14', '10:30', $customer,
                'dashboard', 'en', 'KES', null, 'tenant_user',
            );
            self::fail('A booking inside the notice window should be rejected.');
        } catch (TransitionException $e) {
            self::assertSame('INVALID_TIME', $e->apiCode);
            self::assertStringContainsString('too soon', $e->getMessage());
        }

        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '10:30', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user', true,
        );
        self::assertSame('pending', $created['status']);
    }

    public function testWalkInStartsNowAndSkipsMinNotice(): void
    {
        $this->clock->set('2030-01-14 07:00:00'); // 10:00 Nairobi
        $this->insert('booking_policies', [
            'tenant_id' => $this->tenantA, 'branch_id' => null, 'min_notice_minutes' => 120,
        ]);
        $service = $this->bookingService();
        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '10:05',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'walk_in', 'en', 'KES', null, 'tenant_user',
        );
        self::assertSame('pending', $created['status']);
        self::assertSame('2030-01-14 07:00:00', $created['start_at']);

        $row = $this->pdo->query(
            'SELECT booking_source FROM appointments WHERE tenant_id = ' . $this->tenantA . ' ORDER BY id DESC LIMIT 1',
        )->fetch();
        self::assertSame('walk_in', $row['booking_source']);
    }

    public function testWalkInStillHonoursWorkingHours(): void
    {
        // 21:00 Nairobi — the branch closed at 18:00.
        $this->clock->set('2030-01-14 18:00:00');
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('outside working hours');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '21:00', $customer,
            'walk_in', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testDashboardBookingOnAPastDateIsRejected(): void
    {
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('Cannot book a past date');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2020-01-02', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );
    }

    public function testMaxAdvanceAppliesToDashboardUnlessOverridden(): void
    {
        $this->insert('booking_policies', [
            'tenant_id' => $this->tenantA, 'branch_id' => null, 'max_advance_days' => 30,
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];

        try {
            $service->create(
                $this->tenantA, $this->branchA, 'Africa/Nairobi',
                $this->staffA1, $this->serviceA, '2030-01-14', '10:00', $customer,
                'dashboard', 'en', 'KES', null, 'tenant_user',
            );
            self::fail('Booking beyond the advance window should be rejected.');
        } catch (TransitionException $e) {
            self::assertSame('INVALID_TIME', $e->apiCode);
        }

        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user', true,
        );
        self::assertSame('pending', $created['status']);
    }

    public function testDashboardBookingWithAnExplicitRoomPick(): void
    {
        $service = $this->bookingService();
        $created = $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '12:00',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'dashboard', 'en', 'KES', null, 'tenant_user', false, $this->roomA,
        );
        self::assertSame('pending', $created['status']);

        $rows = $this->pdo->query(
            'SELECT resource_id FROM appointment_resources WHERE tenant_id = ' . $this->tenantA,
        )->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->roomA, (int) $rows[0]['resource_id']);
    }

    public function testExplicitRoomPickOnABusyRoomIsRejected(): void
    {
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null];
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-15', '10:00', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user',
        );

        // Auto-assign would happily find another room — the explicit pick
        // must still honour the room's own freedom.
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('room is not free');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA2, $this->serviceA, '2030-01-15', '10:30', $customer,
            'dashboard', 'en', 'KES', null, 'tenant_user', false, $this->roomA,
        );
    }

    public function testExplicitRoomPickWithTheWrongTypeIsRejected(): void
    {
        $otherType = $this->insert('resource_types', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA, 'name' => 'Chair',
        ]);
        $chair = $this->insert('resources', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'branch_id' => $this->branchA, 'resource_type_id' => $otherType,
            'name' => 'Chair 1', 'capacity' => 1, 'status' => 'active',
        ]);
        $service = $this->bookingService();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('not required for this service');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '14:00',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'dashboard', 'en', 'KES', null, 'tenant_user', false, $chair,
        );
    }

    public function testExplicitRoomPickOnAServiceWithNoRequirementIsRejected(): void
    {
        $plain = $this->insert('services', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA, 'name' => 'Consult',
            'duration_minutes' => 30, 'price_type' => 'fixed',
            'price_amount_minor' => 100000, 'currency' => 'KES',
        ]);
        $this->insert('staff_services', [
            'tenant_id' => $this->tenantA, 'staff_id' => $this->staffA1, 'service_id' => $plain,
        ]);
        $service = $this->bookingService();

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('not required for this service');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $plain, '2030-01-14', '15:00',
            ['name' => 'Guest', 'phone' => null, 'email' => null, 'notes' => null],
            'dashboard', 'en', 'KES', null, 'tenant_user', false, $this->roomA,
        );
    }

    public function testPublicBookingCannotDoubleBookTheSameCustomer(): void
    {
        // A second room so the overlap check — not resource contention —
        // is what rejects the second booking.
        $typeId = (int) $this->pdo->query(
            'SELECT resource_type_id FROM resources WHERE id = ' . $this->roomA,
        )->fetchColumn();
        $this->insert('resources', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantA,
            'branch_id' => $this->branchA, 'resource_type_id' => $typeId,
            'name' => 'Room 2', 'capacity' => 1, 'status' => 'active',
        ]);
        $service = $this->bookingService();
        $customer = ['name' => 'Guest', 'phone' => '+254700000001', 'email' => null, 'notes' => null];
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA1, $this->serviceA, '2030-01-14', '10:00', $customer,
            'public_page', 'en', 'KES', null, 'customer',
        );

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessage('already have an appointment');
        $service->create(
            $this->tenantA, $this->branchA, 'Africa/Nairobi',
            $this->staffA2, $this->serviceA, '2030-01-14', '10:30', $customer,
            'public_page', 'en', 'KES', null, 'customer',
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
            new \App\Modules\Notifications\Services\AppointmentEventService(
                $this->db,
                $this->uuids,
                new \App\Modules\Notifications\Services\NotificationPolicy(
                    $this->db,
                    new \App\Core\Config\Config([]),
                ),
            ),
            new \App\Modules\Billing\Services\FeatureGate($this->db),
            new \App\Modules\Till\Repositories\TillRepository($this->db),
            new \App\Modules\Booking\Services\AvailabilityService(
                new BookingPageRepository($this->db),
                new DateTimeService($this->clock),
            ),
            new \App\Modules\Booking\Repositories\BookingHoldRepository($this->db),
        );
    }
}
