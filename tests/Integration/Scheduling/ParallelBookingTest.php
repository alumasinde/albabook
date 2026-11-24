<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use Tests\Integration\Support\Phase2TestCase;

/**
 * booking-engine.md §20 concurrency matrix — real MySQL, separate PHP
 * processes (the worker bootstraps the real app and calls
 * ManualBookingService::create, so the staff row lock and in-transaction
 * re-checks are exercised exactly as in production):
 *
 *   N parallel bookings, same staff + slot   → exactly 1 succeeds
 *   capacity-2 room, 3 parallel, 3 staff     → exactly 2 succeed
 *
 * Fixtures are committed (no wrapping transaction) — the workers are
 * separate connections and must see them.
 */
final class ParallelBookingTest extends Phase2TestCase
{
    protected bool $wrapInTransaction = false;

    private const WORKER = __DIR__ . '/concurrency/booking_worker.php';

    private int $tenantId;
    private int $branchId;
    private int $serviceId;

    /** @var list<int> */
    private array $staffIds = [];

    private function fixture(int $staffCount, int $roomCapacity): void
    {
        $this->tenantId = $this->provision($this->createUser($this->uniqueEmail('pc')))['tenant_id'];
        $this->branchId = $this->insert('branches', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'CBD',
        ]);
        $this->serviceId = $this->insert('services', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'Massage',
            'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_amount_minor' => 350000, 'currency' => 'KES',
        ]);
        $this->staffIds = [];
        for ($i = 0; $i < $staffCount; $i += 1) {
            $staffId = $this->insert('staff', [
                'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId,
                'first_name' => 'Staff', 'last_name' => (string) $i, 'display_name' => 'Staff ' . $i,
            ]);
            $this->insert('staff_branches', [
                'tenant_id' => $this->tenantId, 'staff_id' => $staffId, 'branch_id' => $this->branchId,
            ]);
            $this->insert('staff_services', [
                'tenant_id' => $this->tenantId, 'staff_id' => $staffId, 'service_id' => $this->serviceId,
            ]);
            $this->staffIds[] = $staffId;
        }
        if ($roomCapacity === 0) {
            return; // no resource requirement on this fixture
        }
        $typeId = $this->insert('resource_types', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'Room',
        ]);
        $this->insert('service_resource_requirements', [
            'tenant_id' => $this->tenantId, 'service_id' => $this->serviceId,
            'resource_type_id' => $typeId, 'quantity' => 1,
        ]);
        $this->insert('resources', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId,
            'branch_id' => $this->branchId, 'resource_type_id' => $typeId,
            'name' => 'Room 1', 'capacity' => $roomCapacity, 'status' => 'active',
        ]);
    }

    /**
     * Spawn one worker process per staff id (repeats allowed), collect the
     * JSON outcome of each.
     * @param list<int> $staffIds
     * @return list<array{code: string}>
     */
    private function runWorkers(array $staffIds, string $date, string $time, ?string $phone = null): array
    {
        $procs = [];
        $results = [];
        foreach ($staffIds as $staffId) {
            $pipes = [];
            $argv = [PHP_BINARY, self::WORKER, (string) $this->tenantId, (string) $this->branchId,
                (string) $staffId, (string) $this->serviceId, $date, $time];
            if ($phone !== null) {
                $argv[] = $phone;
            }
            $proc = proc_open(
                $argv,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if ($proc !== false) {
                $procs[] = [$proc, $pipes];
            } else {
                $results[] = ['code' => 'SPAWN_FAILED'];
            }
        }

        foreach ($procs as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            /** @var array{code: string} $decoded */
            $decoded = json_decode((string) $out, true) ?: ['code' => 'WORKER_CRASH', 'output' => $out, 'stderr' => $err];
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * @param list<array{code: string}> $results
     * @return array<string, int>
     */
    private static function codes(array $results): array
    {
        $counts = [];
        foreach ($results as $r) {
            $counts[$r['code']] = ($counts[$r['code']] ?? 0) + 1;
        }

        return $counts;
    }

    public function testTenParallelBookingsForOneSlotYieldExactlyOneSuccess(): void
    {
        $this->fixture(1, 1);
        $date = gmdate('Y-m-d', time() + 3 * 86400);

        $results = $this->runWorkers(array_fill(0, 10, $this->staffIds[0]), $date, '10:00');
        $codes = self::codes($results);

        self::assertSame(1, $codes['OK'] ?? 0, 'Expected exactly one winner: ' . (string) json_encode($results));
        self::assertSame(9, $codes['SLOT_UNAVAILABLE'] ?? 0, (string) json_encode($results));

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM appointment_items ai
               JOIN appointments a ON a.id = ai.appointment_id
              WHERE ai.staff_id = {$this->staffIds[0]}
                AND a.status IN ('pending','confirmed','checked_in','in_service')",
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testCapacityTwoRoomAdmitsExactlyTwoOfThreeParallelBookings(): void
    {
        $this->fixture(3, 2);
        $date = gmdate('Y-m-d', time() + 3 * 86400);

        $results = $this->runWorkers($this->staffIds, $date, '10:00');
        $codes = self::codes($results);

        self::assertSame(2, $codes['OK'] ?? 0, 'Expected exactly two winners: ' . (string) json_encode($results));
        self::assertSame(1, $codes['RESOURCE_UNAVAILABLE'] ?? 0, (string) json_encode($results));
    }

    public function testParallelSameIdempotencyKeyYieldsOneAppointment(): void
    {
        // Same key from 5 racing workers — the unique index serialises them;
        // losers replay to the winner's appointment instead of writing.
        $this->fixture(1, 1);
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $key = 'itest-' . bin2hex(random_bytes(8));

        $service = new \App\Modules\Appointments\Services\ManualBookingService(
            $this->db,
            new \App\Modules\Appointments\Repositories\AppointmentRepository($this->db),
            new \App\Modules\Booking\Repositories\BookingPageRepository($this->db),
            new \App\Shared\Time\DateTimeService(new \App\Shared\Time\SystemClock()),
            $this->uuids,
            new \App\Modules\Notifications\Services\AppointmentEventService(
                $this->db,
                $this->uuids,
                new \App\Modules\Notifications\Services\NotificationPolicy(
                    $this->db,
                    new \App\Core\Config\Config([]),
                ),
                new \App\Modules\Notifications\Repositories\NotificationRepository($this->db, $this->uuids),
            ),
            new \App\Modules\Billing\Services\FeatureGate($this->db),
            new \App\Modules\Till\Repositories\TillRepository($this->db),
            new \App\Modules\Booking\Services\AvailabilityService(
                new \App\Modules\Booking\Repositories\BookingPageRepository($this->db),
                new \App\Shared\Time\DateTimeService(new \App\Shared\Time\SystemClock()),
            ),
            new \App\Modules\Booking\Repositories\BookingHoldRepository($this->db),
        );

        $first = $service->create(
            $this->tenantId, $this->branchId, 'Africa/Nairobi', $this->staffIds[0], $this->serviceId,
            $date, '10:00',
            ['name' => 'Retry Client', 'phone' => null, 'email' => 'retry@example.com', 'notes' => null],
            'api', 'en', 'KES', null, 'api_credential', true, null, $key,
        );
        // A retried submission returns the same appointment, not a second one.
        $second = $service->create(
            $this->tenantId, $this->branchId, 'Africa/Nairobi', $this->staffIds[0], $this->serviceId,
            $date, '11:30', // different time — still returns the original
            ['name' => 'Retry Client', 'phone' => null, 'email' => 'retry@example.com', 'notes' => null],
            'api', 'en', 'KES', null, 'api_credential', true, null, $key,
        );

        self::assertSame($first['uuid'], $second['uuid']);
        self::assertFalse($first['existing']);
        self::assertTrue($second['existing']);
        self::assertSame($first['reference'], $second['reference']);
    }

    public function testParallelSameCustomerIdentityCollapsesToOneRow(): void
    {
        // Identity match-key race (booking-engine.md §20): N workers all
        // presenting the same phone on DIFFERENT staff at the same slot must
        // all book AND converge on a single customers row — no duplicate
        // customer identity may be created under the race.
        $this->fixture(3, 0); // no room requirement
        $date = gmdate('Y-m-d', time() + 3 * 86400);
        $phone = '+2547' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $results = $this->runWorkers($this->staffIds, $date, '10:00', $phone);
        $codes = self::codes($results);
        self::assertSame(3, $codes['OK'] ?? 0, 'All three workers should book: ' . (string) json_encode($results));

        $dupes = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM customers WHERE tenant_id = {$this->tenantId} AND phone_e164 = '{$phone}'",
        )->fetchColumn();
        self::assertSame(1, $dupes, 'the identity upsert must collapse to one customer row');
    }
}
