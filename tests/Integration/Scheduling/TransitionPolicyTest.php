<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduling;

use App\Core\Config\Config;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\AppointmentTransitionService;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Modules\Notifications\Services\NotificationPolicy;
use App\Shared\Time\DateTimeService;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Tenant-shaped lifecycle policy: require_check_in toggles the direct
 * confirmed → in_service edge (salon flow), visit_window_mode=warn turns
 * hard timing blocks into confirmable warnings recorded on the event, and
 * occupancy counts whatever appointment_statuses.blocks_time says — not a
 * hardcoded list.
 */
final class TransitionPolicyTest extends Phase2TestCase
{
    protected bool $wrapInTransaction = false;

    private int $tenantId;
    private int $branchId;
    private int $staffId;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->set('2030-01-14 10:30:00'); // inside the fixture window

        $this->tenantId = $this->provision($this->createUser($this->uniqueEmail('owner')))['tenant_id'];
        $this->branchId = $this->insert('branches', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'CBD',
        ]);
        $this->serviceId = $this->insert('services', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId, 'name' => 'Massage',
            'duration_minutes' => 60, 'price_type' => 'fixed',
            'price_amount_minor' => 350000, 'currency' => 'KES',
        ]);
        $this->staffId = $this->insert('staff', [
            'uuid' => $this->uuids->generate(), 'tenant_id' => $this->tenantId,
            'first_name' => 'Amina', 'last_name' => 'T', 'display_name' => 'Amina',
        ]);
        $this->insert('staff_branches', ['tenant_id' => $this->tenantId, 'staff_id' => $this->staffId, 'branch_id' => $this->branchId]);
        $this->insert('staff_services', ['tenant_id' => $this->tenantId, 'staff_id' => $this->staffId, 'service_id' => $this->serviceId]);
    }

    private function transitions(): AppointmentTransitionService
    {
        return new AppointmentTransitionService(
            $this->db,
            new AppointmentRepository($this->db),
            new AppointmentEventService($this->db, $this->uuids, new NotificationPolicy($this->db, new Config([])),
                new NotificationRepository($this->db, $this->uuids)),
            new DateTimeService($this->clock),
        );
    }

    private function repo(): AppointmentRepository
    {
        return new AppointmentRepository($this->db);
    }

    /** Fixture appointment: 2030-01-14 10:00–11:00 UTC (clock sits 10:30). */
    private function makeAppointment(string $status = 'confirmed'): string
    {
        $uuid = $this->uuids->generate();
        $id = $this->insert('appointments', [
            'uuid' => $uuid, 'tenant_id' => $this->tenantId, 'branch_id' => $this->branchId,
            'reference' => substr(bin2hex(random_bytes(4)), 0, 8), 'booking_source' => 'dashboard',
            'status' => $status, 'customer_name_snapshot' => 'Test Client', 'currency' => 'KES',
            'start_at' => '2030-01-14 10:00:00', 'end_at' => '2030-01-14 11:00:00',
            'policy_version' => 1,
        ]);
        $this->insert('appointment_items', [
            'tenant_id' => $this->tenantId, 'appointment_id' => $id, 'item_position' => 1,
            'service_id' => $this->serviceId, 'staff_id' => $this->staffId,
            'service_name_snapshot' => 'Massage', 'price_type' => 'fixed',
            'duration_minutes' => 60, 'start_at' => '2030-01-14 10:00:00', 'end_at' => '2030-01-14 11:00:00',
            'occupied_start_at' => '2030-01-14 10:00:00', 'occupied_end_at' => '2030-01-14 11:00:00',
            'currency' => 'KES', 'policy_version' => 1,
        ]);

        return $uuid;
    }

    /** @param array<string, mixed> $prefs */
    private function setPrefs(array $prefs): void
    {
        $this->pdo->prepare(
            'INSERT INTO tenant_settings (tenant_id, preferences) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE preferences = VALUES(preferences)',
        )->execute([$this->tenantId, json_encode($prefs)]);
    }

    public function testDirectStartBlockedWhileCheckInRequired(): void
    {
        $uuid = $this->makeAppointment('confirmed');
        $this->setPrefs(['require_check_in' => 1]);

        $this->expectException(TransitionException::class);
        $this->expectExceptionMessageMatches('/Cannot move/');
        try {
            $this->transitions()->transition($this->tenantId, $uuid, 'in_service', null, null);
        } catch (TransitionException $e) {
            self::assertSame('INVALID_TRANSITION', $e->apiCode);

            throw $e;
        }
    }

    public function testSalonModeUnlocksConfirmedToInService(): void
    {
        $uuid = $this->makeAppointment('confirmed');
        $this->setPrefs(['require_check_in' => 0]);

        $row = $this->transitions()->transition($this->tenantId, $uuid, 'in_service', null, null);

        self::assertSame('in_service', $row['status']);
    }

    public function testWarnModeAsksForConfirmationInsteadOfBlocking(): void
    {
        // Appointment ended 2030-01-14 11:00; clock jumps 26h ahead — far
        // outside the 240-minute late window for check-in.
        $uuid = $this->makeAppointment('confirmed');
        $this->setPrefs(['visit_window_mode' => 'warn']);
        $this->clock->set('2030-01-15 13:00:00');

        try {
            $this->transitions()->transition($this->tenantId, $uuid, 'checked_in', null, null);
            self::fail('Warn mode must ask for confirmation, not silently allow.');
        } catch (TransitionException $e) {
            self::assertSame('TIMING_WARNING', $e->apiCode);
            self::assertSame(['confirmable' => true], $e->fields);
        }

        // Confirmable does not mean applied — status is untouched.
        $stmt = $this->pdo->prepare('SELECT status FROM appointments WHERE tenant_id = ? AND uuid = ?');
        $stmt->execute([$this->tenantId, $uuid]);
        self::assertSame('confirmed', $stmt->fetchColumn());
    }

    public function testForceOverrideAppliesAndRecordsOnEvent(): void
    {
        $uuid = $this->makeAppointment('confirmed');
        $this->setPrefs(['visit_window_mode' => 'warn']);
        $this->clock->set('2030-01-15 13:00:00');

        $row = $this->transitions()->transition($this->tenantId, $uuid, 'checked_in', null, null, force: true);
        self::assertSame('checked_in', $row['status']);

        $stmt = $this->pdo->prepare(
            "SELECT metadata FROM appointment_events WHERE tenant_id = ? AND event_type = 'status.checked_in' ORDER BY id DESC LIMIT 1",
        );
        $stmt->execute([$this->tenantId]);
        $meta = json_decode((string) $stmt->fetchColumn(), true);
        self::assertArrayHasKey('timing_override', $meta);
    }

    public function testStrictModeStillBlocksOutOfWindow(): void
    {
        $uuid = $this->makeAppointment('confirmed');
        // No prefs row → strict default.
        $this->clock->set('2030-01-15 13:00:00');

        try {
            $this->transitions()->transition($this->tenantId, $uuid, 'checked_in', null, null, force: true);
            self::fail('Strict mode must block even when force is passed.');
        } catch (TransitionException $e) {
            self::assertSame('TOO_LATE', $e->apiCode);
        }
    }

    public function testEachTransitionStampsItsOwnColumn(): void
    {
        $uuid = $this->makeAppointment('pending');
        $t = $this->transitions();
        foreach (['confirmed', 'checked_in', 'in_service', 'completed'] as $to) {
            $t->transition($this->tenantId, $uuid, $to, null, null);
        }

        $stmt = $this->pdo->prepare(
            'SELECT confirmed_at, checked_in_at, in_service_at, completed_at, cancelled_at
               FROM appointments WHERE tenant_id = ? AND uuid = ?',
        );
        $stmt->execute([$this->tenantId, $uuid]);
        $row = $stmt->fetch();

        self::assertNotNull($row['confirmed_at']);
        self::assertNotNull($row['checked_in_at']);
        self::assertNotNull($row['in_service_at']);
        self::assertNotNull($row['completed_at']);
        self::assertNull($row['cancelled_at']);
        self::assertLessThanOrEqual($row['completed_at'], $row['confirmed_at']);
    }

    public function testOccupancyFollowsBlocksTimeFlagNotAList(): void
    {
        $uuid = $this->makeAppointment('pending');
        $stmt = $this->pdo->prepare('SELECT id FROM appointments WHERE tenant_id = ? AND uuid = ?');
        $stmt->execute([$this->tenantId, $uuid]);
        $appointmentId = (int) $stmt->fetchColumn();

        // A status the engine has never heard of — only blocks_time decides
        // whether it occupies the staff window.
        $this->pdo->exec(
            "INSERT INTO appointment_statuses (code, name, blocks_time, is_terminal)
             VALUES ('waitlisted', 'Waitlisted', 1, 0)",
        );
        $this->pdo->prepare('UPDATE appointments SET status = ? WHERE tenant_id = ? AND id = ?')
            ->execute(['waitlisted', $this->tenantId, $appointmentId]);

        $occupied = fn(): int => $this->repo()->staffOccupied(
            $this->tenantId, $this->staffId, '2030-01-14 09:00:00', '2030-01-14 12:00:00',
        );
        self::assertSame(1, $occupied(), 'a blocks_time=1 status must occupy the window');

        $this->pdo->exec("UPDATE appointment_statuses SET blocks_time = 0 WHERE code = 'waitlisted'");
        self::assertSame(0, $occupied(), 'flipping blocks_time frees the window without code changes');

        // Dereference the fixture before removing the shared status row.
        $this->pdo->prepare(
            "UPDATE appointments SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(6), cancelled_by = 'business'
              WHERE tenant_id = ? AND id = ?",
        )->execute([$this->tenantId, $appointmentId]);
        $this->pdo->exec("DELETE FROM appointment_statuses WHERE code = 'waitlisted'");
    }
}
