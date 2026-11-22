<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Database\IsolationLevel;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Appointments\Services\TransitionException;
use App\Modules\Booking\Repositories\BookingHoldRepository;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Shared\Time\DateTimeService;

/**
 * Booking holds (booking-engine.md §14): a short-lived reservation that
 * blocks a staff member's buffer-inclusive window — and any required
 * resources — while the customer fills in the form. Holds are created
 * through the same grid validation as bookings (the slot must be one the
 * availability engine would have offered), then re-verified inside a
 * transaction under the staff row lock, so hold-vs-booking and hold-vs-hold
 * races resolve exactly like booking races (D-009).
 *
 * Conversion happens inside ManualBookingService::create: the hold token is
 * validated, its occupancy excluded from the conflict re-check, and the
 * row is marked 'converted' in the same transaction as the appointment.
 */
final class BookingHoldService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingHoldRepository $holds,
        private readonly AppointmentRepository $appointments,
        private readonly BookingPageRepository $pages,
        private readonly AvailabilityService $availability,
        private readonly DateTimeService $dates,
        private readonly Config $config,
    ) {}

    /**
     * Canonical hash binding a hold to the exact booking it reserves —
     * conversion re-computes this from the booking request and rejects a
     * hold presented for a different slot (HOLD_MISMATCH).
     */
    public static function requestHash(
        int $tenantId,
        int $branchId,
        int $staffId,
        int $serviceId,
        string $startUtc,
        string $endUtc,
    ): string {
        return hash('sha256', implode('|', [$tenantId, $branchId, $staffId, $serviceId, $startUtc, $endUtc]));
    }

    /**
     * Reserve $staffId's window for $serviceId on $localDate/$localTime
     * (branch-local). Returns the raw token — stored only as a hash.
     *
     * @param array<string, mixed> $branch   booking-page branch row (id, timezone)
     * @param array<string, mixed> $service  services row (id, duration_minutes, buffers)
     * @return array{hold_token: string, expires_at: string}
     */
    public function create(
        int $tenantId,
        array $branch,
        array $service,
        int $staffId,
        string $localDate,
        string $localTime,
        ?string $clientFingerprint,
    ): array {
        $tenantId = (int) $tenantId;
        $branchId = (int) $branch['id'];
        $branchTz = (string) ($branch['timezone'] ?: 'Africa/Nairobi');

        // A hold may only be taken on a slot the public grid would offer —
        // this inherits notice, window, hours, breaks and occupancy rules.
        if (!$this->availability->slotAvailableForStaff($tenantId, $branch, $service, $staffId, $localDate, $localTime, $branchTz)) {
            throw new TransitionException('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
        }

        $policy = $this->pages->policy($tenantId, $branchId) ?? [];
        $maxActive = $this->config->int('booking.max_active_holds_per_client', 2);
        $ttlSeconds = (int) ($policy['hold_ttl_seconds'] ?? 0)
            ?: $this->config->int('booking.hold_ttl_seconds', 300);

        if ($clientFingerprint !== null
            && $this->holds->countActiveForClient($tenantId, $clientFingerprint) >= $maxActive) {
            throw new TransitionException('POLICY_VIOLATION', 'Too many active reservations.', 429, ['hold' => 'You already have an active reservation.']);
        }

        $duration = (int) $service['duration_minutes'];
        $start = $this->dates->localToUtc($localDate, $localTime, $branchTz);
        if ($start === null) {
            throw new TransitionException('VALIDATION_FAILED', 'That time does not exist.', 422, ['time' => 'Pick a valid time.']);
        }
        $end = $start->modify('+' . $duration . ' minutes');
        $occupiedStart = $start->modify('-' . (int) $service['buffer_before_minutes'] . ' minutes');
        $occupiedEnd = $end->modify('+' . (int) $service['buffer_after_minutes'] . ' minutes');
        $requestHash = self::requestHash(
            $tenantId, $branchId, $staffId, (int) $service['id'],
            $this->dates->toDb($start), $this->dates->toDb($end),
        );
        $token = bin2hex(random_bytes(24));
        $expiresAt = $this->dates->now()->modify("+{$ttlSeconds} seconds");

        return $this->db->transaction(function () use (
            $tenantId, $branchId, $branchTz, $staffId, $service,
            $occupiedStart, $occupiedEnd, $localDate,
            $requestHash, $token, $expiresAt, $clientFingerprint,
        ): array {
            $staff = $this->appointments->lockStaff($tenantId, $staffId);
            if ($staff === null || $staff['status'] !== 'active') {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available.', 422, ['staff' => 'Choose available staff.']);
            }
            if ($this->appointments->staffOccupied(
                $tenantId, $staffId,
                $this->dates->toDb($occupiedStart), $this->dates->toDb($occupiedEnd),
            ) > 0) {
                throw new TransitionException('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
            }
            if ($this->appointments->staffUnavailableConflict(
                $tenantId, $staffId, $branchId, $localDate,
                $this->dates->toDb($occupiedStart), $this->dates->toDb($occupiedEnd),
            ) > 0) {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available at that time.', 409, ['time' => 'Pick another time.']);
            }

            // Resources reserve like bookings do: freeResourceForType counts
            // both live allocations and other active holds.
            $tz = new \DateTimeZone($branchTz);
            $localStart = $occupiedStart->setTimezone($tz);
            $localEnd = $occupiedEnd->setTimezone($tz);
            $resourceHolds = [];
            foreach ($this->appointments->serviceRequirements($tenantId, (int) $service['id']) as $requirement) {
                $resourceTypeId = (int) $requirement['resource_type_id'];
                $quantity = (int) $requirement['quantity'];
                $this->appointments->lockResourcesForType($tenantId, $branchId, $resourceTypeId);
                $allocatedId = $this->appointments->freeResourceForType(
                    $tenantId, $branchId, $resourceTypeId,
                    $this->dates->toDb($occupiedStart), $this->dates->toDb($occupiedEnd),
                    (int) $localStart->format('N'),
                    $localStart->format('H:i:s'), $localEnd->format('H:i:s'),
                    $quantity, $localStart->format('Y-m-d'),
                );
                if ($allocatedId === null) {
                    throw new TransitionException('RESOURCE_UNAVAILABLE', 'No room or equipment is free at that time.', 409, ['time' => 'Pick another time.']);
                }
                $resourceHolds[] = ['resource_id' => $allocatedId, 'quantity' => $quantity];
            }

            $holdId = $this->holds->insertHold([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'token_hash' => hash('sha256', $token),
                'status' => 'active',
                'expires_at' => $this->dates->toDb($expiresAt),
                'request_hash' => $requestHash,
                'client_fingerprint' => $clientFingerprint,
            ]);
            $this->holds->insertHoldItem([
                'tenant_id' => $tenantId,
                'hold_id' => $holdId,
                'item_position' => 0,
                'staff_id' => $staffId,
                'occupied_start_at' => $this->dates->toDb($occupiedStart),
                'occupied_end_at' => $this->dates->toDb($occupiedEnd),
            ]);
            foreach ($resourceHolds as $resourceHold) {
                $this->holds->insertHoldResource([
                    'tenant_id' => $tenantId,
                    'hold_id' => $holdId,
                    'item_position' => 0,
                    'resource_id' => $resourceHold['resource_id'],
                    'occupied_start_at' => $this->dates->toDb($occupiedStart),
                    'occupied_end_at' => $this->dates->toDb($occupiedEnd),
                ]);
            }

            return [
                'hold_token' => $token,
                'expires_at' => $this->dates->toDb($expiresAt),
            ];
        }, IsolationLevel::ReadCommitted);
    }

    /**
     * Release a hold early (customer navigated away / picked another slot).
     * Idempotent: releasing an already released/expired/converted hold is a
     * no-op success so clients can call it unconditionally.
     */
    public function release(int $tenantId, string $holdToken): void
    {
        $hold = $this->holds->findActiveByTokenHash(hash('sha256', $holdToken));
        if ($hold !== null && (int) $hold['tenant_id'] === $tenantId) {
            $this->holds->release($tenantId, (int) $hold['id']);
        }
    }
}
