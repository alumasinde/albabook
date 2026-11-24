<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use App\Core\Config\Config;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\ManualBookingService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Booking\Repositories\BookingHoldRepository;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingHoldService;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Time\DateTimeService;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Booking-hold lifecycle (booking-engine.md §17/§20): a hold blocks the
 * staff window for everyone else, converts atomically into the held staff's
 * appointment, and expired/released holds neither block nor convert.
 */
final class BookingHoldTest extends Phase2TestCase
{
    // Services open their own transactions — committed fixtures like the
    // other Scheduling tests (no wrapping transaction).
    protected bool $wrapInTransaction = false;

    private int $tenantId;
    private int $branchId;
    private int $serviceId;
    private int $staffA;
    private int $staffB;

    private const DAY = '2030-01-14'; // a Wednesday inside all fixture shifts

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2030-01-13 12:00:00'); // well before the booking day

        $this->tenantId = $this->provision($this->createUser($this->uniqueEmail('holder')))['tenant_id'];
        $this->branchId = $this->insert('branches', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'CBD',
        ]);
        $this->serviceId = $this->insert('services', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'Massage',
            'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_amount_minor' => 350000, 'currency' => 'KES',
        ]);
        $this->staffA = $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId,
            'first_name' => 'Amina', 'last_name' => 'T', 'display_name' => 'Amina',
        ]);
        $this->staffB = $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId,
            'first_name' => 'Brian', 'last_name' => 'T', 'display_name' => 'Brian',
        ]);
        foreach ([$this->staffA, $this->staffB] as $staffId) {
            $this->insert('staff_branches', [
                'tenant_id' => $this->tenantId, 'staff_id' => $staffId, 'branch_id' => $this->branchId,
            ]);
            $this->insert('staff_services', [
                'tenant_id' => $this->tenantId, 'staff_id' => $staffId, 'service_id' => $this->serviceId,
            ]);
            for ($weekday = 1; $weekday <= 7; $weekday += 1) {
                $this->insert('staff_schedules', [
                    'tenant_id' => $this->tenantId, 'staff_id' => $staffId, 'branch_id' => $this->branchId,
                    'weekday' => $weekday, 'starts_at' => '08:00:00', 'ends_at' => '18:00:00',
                ]);
            }
        }
        for ($weekday = 1; $weekday <= 7; $weekday += 1) {
            $this->insert('business_hours', [
                'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
                'weekday' => $weekday, 'opens_at' => '08:00:00', 'closes_at' => '18:00:00',
            ]);
        }
    }

    private function holds(): BookingHoldService
    {
        return new BookingHoldService(
            $this->db,
            new BookingHoldRepository($this->db),
            new AppointmentRepository($this->db),
            new BookingPageRepository($this->db),
            new AvailabilityService(new BookingPageRepository($this->db), new DateTimeService($this->clock)),
            new DateTimeService($this->clock),
            new Config(['booking' => ['max_active_holds_per_client' => 2, 'hold_ttl_seconds' => 300]]),
        );
    }

    private function bookings(): ManualBookingService
    {
        return new ManualBookingService(
            $this->db,
            new AppointmentRepository($this->db),
            new BookingPageRepository($this->db),
            new DateTimeService($this->clock),
            $this->uuids,
            new AppointmentEventService($this->db, $this->uuids, new NotificationPolicy($this->db, new Config([])),
                new NotificationRepository($this->db, $this->uuids)),
            new FeatureGate($this->db),
            new TillRepository($this->db),
            new AvailabilityService(new BookingPageRepository($this->db), new DateTimeService($this->clock)),
            new BookingHoldRepository($this->db),
        );
    }

    /** @return array<string, mixed> */
    private function branch(): array
    {
        return ['id' => $this->branchId, 'timezone' => 'Africa/Nairobi'];
    }

    /** @return array<string, mixed> */
    private function service(): array
    {
        return [
            'id' => $this->serviceId, 'duration_minutes' => 60,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        ];
    }

    /** @return array{name: string, phone: ?string, email: ?string, notes: ?string} */
    private function customer(string $name = 'Holder'): array
    {
        return ['name' => $name, 'phone' => null, 'email' => null, 'notes' => null];
    }

    /** @return array<string, mixed>|null */
    private function holdRow(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, converted_appointment_id, expires_at FROM booking_holds
             WHERE tenant_id = :t AND token_hash = :h',
        );
        $stmt->execute(['t' => $this->tenantId, 'h' => hash('sha256', $token)]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string, mixed> */
    private function book(int $staffId, string $time, ?string $holdToken = null): array
    {
        return $this->bookings()->create(
            $this->tenantId, $this->branchId, 'Africa/Nairobi', $staffId, $this->serviceId,
            self::DAY, $time, $this->customer(), 'public_page', 'en', 'KES',
            null, 'customer', false, null, null, $holdToken,
        );
    }

    public function testHoldBlocksStaffForOthersAndConvertsForHolder(): void
    {
        $hold = $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '10:00', 'fp-1');

        // Someone else's booking for the held staff/slot is rejected.
        try {
            $this->book($this->staffA, '10:00');
            self::fail('A competing booking should lose to the active hold.');
        } catch (TransitionException $e) {
            self::assertSame('SLOT_UNAVAILABLE', $e->apiCode);
        }

        // The holder converts: same appointment lands on the held staff.
        $created = $this->book($this->staffA, '10:00', $hold['hold_token']);
        self::assertSame('pending', $created['status']);
        $row = $this->holdRow($hold['hold_token']);
        self::assertNotNull($row);
        self::assertSame('converted', $row['status']);
        $items = (new AppointmentRepository($this->db))->itemsFor(
            $this->tenantId,
            (int) $this->pdo->query("SELECT id FROM appointments WHERE uuid = '{$created['uuid']}'")->fetchColumn(),
        );
        self::assertSame($this->staffA, (int) $items[0]['staff_id']);
    }

    public function testExpiredHoldCannotConvertAndDoesNotBlock(): void
    {
        $hold = $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '10:00', 'fp-2');
        $row = $this->holdRow($hold['hold_token']);
        self::assertNotNull($row);
        // Occupancy filters use MySQL UTC_TIMESTAMP(), not the frozen clock —
        // the expiry must be in real past time.
        $this->pdo->prepare('UPDATE booking_holds SET expires_at = :e WHERE id = :id')
            ->execute(['e' => '2020-01-01 00:00:00', 'id' => $row['id']]);

        try {
            $this->book($this->staffA, '10:00', $hold['hold_token']);
            self::fail('An expired hold must not convert.');
        } catch (TransitionException $e) {
            self::assertSame('HOLD_EXPIRED', $e->apiCode);
        }

        // The expired hold no longer blocks — a fresh booking lands.
        $created = $this->book($this->staffA, '10:00');
        self::assertSame('pending', $created['status']);
    }

    public function testReleasedHoldFreesTheTimeAndCannotConvert(): void
    {
        $hold = $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '11:00', 'fp-3');
        $this->holds()->release($this->tenantId, $hold['hold_token']);
        $row = $this->holdRow($hold['hold_token']);
        self::assertNotNull($row);
        self::assertSame('released', $row['status']);

        try {
            $this->book($this->staffA, '11:00', $hold['hold_token']);
            self::fail('A released hold must not convert.');
        } catch (TransitionException $e) {
            self::assertSame('HOLD_EXPIRED', $e->apiCode);
        }

        $created = $this->book($this->staffA, '11:00');
        self::assertSame('pending', $created['status']);
    }

    public function testHoldForADifferentSlotIsRejected(): void
    {
        $hold = $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '12:00', 'fp-4');

        // request_hash binds tenant+branch+staff+service+window — a different
        // slot (or staff) presented with the token is a hard mismatch.
        try {
            $this->book($this->staffA, '13:00', $hold['hold_token']);
            self::fail('A hold for 12:00 must not convert into a 13:00 booking.');
        } catch (TransitionException $e) {
            self::assertSame('VALIDATION_FAILED', $e->apiCode);
        }
    }

    public function testConversionPreservesTheHeldStaff(): void
    {
        $hold = $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffB, self::DAY, '14:00', 'fp-5');

        // The hash binds the held staff too — presenting another member fails.
        try {
            $this->book($this->staffA, '14:00', $hold['hold_token']);
            self::fail('A hold for staff B must not convert onto staff A.');
        } catch (TransitionException $e) {
            self::assertSame('VALIDATION_FAILED', $e->apiCode);
        }

        $created = $this->book($this->staffB, '14:00', $hold['hold_token']);
        $apptId = (int) $this->pdo->query(
            "SELECT id FROM appointments WHERE uuid = '{$created['uuid']}'",
        )->fetchColumn();
        $items = (new AppointmentRepository($this->db))->itemsFor($this->tenantId, $apptId);
        self::assertSame($this->staffB, (int) $items[0]['staff_id']);
    }

    public function testActiveHoldLimitPerClient(): void
    {
        $holds = $this->holds();
        $holds->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '09:00', 'fp-6');
        $holds->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '10:00', 'fp-6');
        try {
            $holds->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '11:00', 'fp-6');
            self::fail('A third active hold for the same client must be rejected.');
        } catch (TransitionException $e) {
            self::assertSame('POLICY_VIOLATION', $e->apiCode);
        }
    }

    public function testOfferedSlotsNeverOverlapBusyWindows(): void
    {
        // Property-style check (booking-engine.md §20): seed busy windows from
        // a real appointment + a hold, then every slot the grid offers must
        // fall outside every occupied range for that staff.
        $this->bookings()->create(
            $this->tenantId, $this->branchId, 'Africa/Nairobi', $this->staffA, $this->serviceId,
            self::DAY, '10:00', $this->customer(), 'dashboard', 'en', 'KES', null, 'tenant_user', true,
        );
        $this->holds()->create($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, '13:00', 'fp-7');

        $availability = new AvailabilityService(new BookingPageRepository($this->db), new DateTimeService($this->clock));
        $slots = $availability->slots($this->tenantId, $this->branch(), $this->service(), $this->staffA, self::DAY, 'Africa/Nairobi');
        $ranges = (new BookingPageRepository($this->db))->occupiedRanges(
            $this->tenantId, [$this->staffA], self::DAY . ' 00:00:00', '2030-01-15 00:00:00',
        );

        self::assertNotEmpty($slots, 'the grid should still offer free time');
        $dates = new DateTimeService($this->clock);
        foreach ($slots as $slot) {
            $slotStart = $dates->localToUtc(self::DAY, $slot['start_time'], 'Africa/Nairobi');
            $slotEnd = $slotStart->modify('+60 minutes');
            foreach ($ranges as $range) {
                $busyStart = new \DateTimeImmutable((string) $range['occupied_start_at'], new \DateTimeZone('UTC'));
                $busyEnd = new \DateTimeImmutable((string) $range['occupied_end_at'], new \DateTimeZone('UTC'));
                self::assertTrue(
                    $slotEnd <= $busyStart || $slotStart >= $busyEnd,
                    "slot {$slot['start_time']} overlaps a busy window",
                );
            }
        }
    }
}
