<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Services;

use App\Core\Database\Database;
use App\Core\Database\IsolationLevel;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Billing\Services\FeatureGate;
use App\Modules\Booking\Repositories\BookingHoldRepository;
use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingHoldService;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Modules\Till\Repositories\TillRepository;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\DateTimeService;

/**
 * Minimal manual-booking write path used by the dashboard "New appointment"
 * modal and the public booking page. Holds, deposits and the full lock
 * protocol arrive with the Phase 4 booking engine; this path still serialises
 * on the staff row lock (D-009) and re-checks occupancy inside the
 * transaction, so two writers can never double-book a staff member.
 */
final class ManualBookingService
{
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Database $db,
        private readonly AppointmentRepository $appointments,
        private readonly BookingPageRepository $pages,
        private readonly DateTimeService $dates,
        private readonly UuidFactory $uuids,
        private readonly AppointmentEventService $events,
        private readonly FeatureGate $features,
        private readonly TillRepository $till,
        private readonly AvailabilityService $availability,
        private readonly BookingHoldRepository $holds,
    ) {}

    /**
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     * @return array{uuid: string, reference: string, start_at: string, end_at: string, status: string, manage_token: ?string, existing: bool}
     */
    public function create(
        int $tenantId,
        int $branchId,
        string $branchTimezone,
        int $staffId,
        int $serviceId,
        string $localDate,
        string $localTime,
        array $customer,
        string $source,
        string $locale,
        string $currency,
        ?int $actorUserId,
        string $actorType,
        bool $canOverridePolicy = false,
        ?int $resourceId = null,
        ?string $idempotencyKey = null,
        ?string $holdToken = null,
        bool $phoneVerified = false,
    ): array {
        // Exactly-once booking (booking-engine.md §18): a client that
        // retries with the same key gets the original appointment back.
        if ($idempotencyKey !== null) {
            $existing = $this->appointments->findByIdempotencyKey($tenantId, $idempotencyKey);
            if ($existing !== null) {
                return $this->result($existing, null, true);
            }
        }

        // Public write paths — booking page, widget, REST API — all share
        // the same eligibility and customer-overlap rules.
        $publicSource = in_array($source, ['public_page', 'widget', 'api'], true);
        $service = $this->appointments->serviceFor($tenantId, $serviceId, $branchId);
        if ($service === null || ($publicSource && !$service['is_bookable_online'])) {
            throw new TransitionException('SERVICE_UNAVAILABLE', 'That service is not bookable.', 422, ['service' => 'Choose a bookable service.']);
        }
        if (array_key_exists('branch_bookable', $service) && $service['branch_bookable'] !== null && !$service['branch_bookable']) {
            throw new TransitionException('SERVICE_UNAVAILABLE', 'That service is not bookable at this branch.', 422, ['service' => 'Choose a bookable service.']);
        }
        // Assignment and capability are integrity checks, not policy —
        // no permission bypasses them.
        if (!$this->appointments->staffAssignedToBranch($tenantId, $staffId, $branchId)) {
            throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not assigned to this branch.', 422, ['staff' => 'Choose staff assigned to this branch.']);
        }
        if (!$this->appointments->staffCanPerform($tenantId, $staffId, $serviceId)) {
            throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member does not offer this service.', 422, ['staff' => 'Choose staff who offer this service.']);
        }

        $duration = (int) $service['duration_minutes'];
        $bufferBefore = (int) $service['buffer_before_minutes'];
        $bufferAfter = (int) $service['buffer_after_minutes'];
        $price = $service['branch_price'] ?? $service['price_amount_minor'] ?? $service['min_amount_minor'] ?? 0;

        if ($source === 'walk_in') {
            // Walk-ins start now (docs §12) — the form's date/time are
            // ignored and the notice rules do not apply.
            $start = $this->dates->now();
            $localDate = $start->setTimezone(new \DateTimeZone($branchTimezone))->format('Y-m-d');
        } else {
            $start = $this->dates->localToUtc($localDate, $localTime, $branchTimezone);
            if ($start === null) {
                throw new TransitionException('INVALID_TIME', 'That time does not exist.', 422, ['time' => 'Pick a valid time.']);
            }
        }
        if ($start < $this->dates->now() && !in_array($source, ['dashboard', 'walk_in'], true)) {
            throw new TransitionException('INVALID_TIME', 'That time has already passed.', 422, ['time' => 'Pick a future time.']);
        }
        // Same-day backfill (a walk-in that started early) is fine, but a
        // staff booking cannot move into a past branch-local date.
        if ($source === 'dashboard') {
            $localToday = $this->dates->now()->setTimezone(new \DateTimeZone($branchTimezone))->format('Y-m-d');
            if ($localDate < $localToday) {
                throw new TransitionException('INVALID_TIME', 'Cannot book a past date.', 422, ['date' => 'Pick today or a future date.']);
            }
        }
        $policy = $this->pages->policy($tenantId, $branchId) ?? [];
        $maxAdvance = $policy['max_advance_days'] ?? null;
        if ($maxAdvance !== null && ($publicSource || !$canOverridePolicy)) {
            $latest = $this->dates->now()
                ->setTimezone(new \DateTimeZone($branchTimezone))
                ->modify('+' . (int) $maxAdvance . ' days');
            if ($localDate > $latest->format('Y-m-d')) {
                throw new TransitionException('INVALID_TIME', 'That date is too far ahead.', 422, ['date' => 'Pick a nearer date.']);
            }
        }
        // Dashboard bookings respect the tenant's minimum notice (docs §12:
        // bypass needs appointments.override_policy). Public slots already
        // carry the notice rule; walk-ins start now by definition.
        $minNotice = (int) ($policy['min_notice_minutes'] ?? 30);
        if ($source === 'dashboard' && !$canOverridePolicy
            && $start < $this->dates->now()->modify("+{$minNotice} minutes")) {
            throw new TransitionException('INVALID_TIME', 'That slot is too soon.', 422, ['time' => "This service needs at least {$minNotice} minutes of notice."]);
        }
        $end = $start->modify('+' . $duration . ' minutes');
        $occupiedStart = $start->modify('-' . $bufferBefore . ' minutes');
        $occupiedEnd = $end->modify('+' . $bufferAfter . ' minutes');

        // Staff bookings follow the same availability rules as the public
        // grid — business hours, shift, breaks (docs §12). Public-page
        // bookings were already validated by slotAvailableForStaff.
        // appointments.override_policy is the explicit bypass.
        if ($source !== 'public_page' && !$canOverridePolicy) {
            $startLocal = $start->setTimezone(new \DateTimeZone($branchTimezone));
            $startMinute = (int) $startLocal->format('H') * 60 + (int) $startLocal->format('i');
            if (!$this->availability->fitsWorkingWindow(
                $tenantId,
                $branchId,
                $staffId,
                $localDate,
                $startMinute,
                $duration,
                $branchTimezone,
            )) {
                throw new TransitionException('OUTSIDE_HOURS', 'That time is outside working hours or a break.', 422, ['time' => 'Pick a time inside working hours.']);
            }
        }

        return $this->db->transaction(function () use (
            $tenantId,
            $branchId,
            $branchTimezone,
            $staffId,
            $service,
            $duration,
            $bufferBefore,
            $bufferAfter,
            $price,
            $start,
            $end,
            $occupiedStart,
            $occupiedEnd,
            $localDate,
            $customer,
            $source,
            $locale,
            $currency,
            $actorUserId,
            $actorType,
            $resourceId,
            $idempotencyKey,
            $holdToken,
            $publicSource,
            $policy,
            $phoneVerified,
        ): array {
            // A finite monthly cap is counted under a lock on the tenant row,
            // so two simultaneous bookings at 499/500 cannot both pass. Taken
            // before the staff lock (fixed order: tenant → staff → resources)
            // and only for capped plans, so uncapped tenants keep full
            // booking concurrency.
            if ($this->features->limit($tenantId, 'monthly_bookings') !== null) {
                $this->appointments->lockTenantForQuota($tenantId);
            }
            $bookingCount = $this->appointments->countCreatedSince($tenantId, gmdate('Y-m-01 00:00:00'));
            if (!$this->features->underLimit($tenantId, 'monthly_bookings', $bookingCount)) {
                throw new TransitionException('BOOKING_LIMIT', 'This business has reached its monthly booking limit.', 422, ['date' => 'Monthly booking limit reached.']);
            }
            $staff = $this->appointments->lockStaff($tenantId, $staffId);
            if ($staff === null || $staff['status'] !== 'active') {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available.', 422, ['staff' => 'Choose available staff.']);
            }

            // A presented hold must be converted in this same
            // transaction: locked FOR UPDATE, still active and
            // unexpired, and bound to exactly this slot by request_hash
            // — otherwise the token reserves nothing.
            $holdId = null;
            if ($holdToken !== null) {
                $hold = $this->holds->lockByTokenHash(hash('sha256', $holdToken));
                if ($hold === null
                    || (int) $hold['tenant_id'] !== $tenantId
                    || $hold['status'] !== 'active'
                    || (string) $hold['expires_at'] <= $this->dates->toDb($this->dates->now())) {
                    throw new TransitionException('HOLD_EXPIRED', 'Your reservation expired — pick the time again.', 410, ['time' => 'Reservation expired. Pick the time again.']);
                }
                $expectedHash = BookingHoldService::requestHash(
                    $tenantId,
                    $branchId,
                    $staffId,
                    (int) $service['id'],
                    $this->dates->toDb($start),
                    $this->dates->toDb($end),
                );
                if (!hash_equals($expectedHash, (string) $hold['request_hash'])) {
                    throw new TransitionException('VALIDATION_FAILED', 'That reservation does not match this booking.', 422, ['hold_token' => 'The reservation is for a different slot.']);
                }
                $holdId = (int) $hold['id'];
            }

            $conflicts = $this->appointments->staffOccupied(
                $tenantId,
                $staffId,
                $this->dates->toDb($occupiedStart),
                $this->dates->toDb($occupiedEnd),
                null,
                $holdId,
            );
            if ($conflicts > 0) {
                throw new TransitionException('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
            }
            if ($this->appointments->staffUnavailableConflict(
                $tenantId,
                $staffId,
                $branchId,
                $localDate,
                $this->dates->toDb($occupiedStart),
                $this->dates->toDb($occupiedEnd),
            ) > 0) {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available at that time.', 409, ['time' => 'Pick another time.']);
            }

            $resourceAssignments = [];
            $tz = new \DateTimeZone($branchTimezone);
            $localStart = $occupiedStart->setTimezone($tz);
            $localEnd = $occupiedEnd->setTimezone($tz);
            // An explicit room pick (dashboard modal) is validated with
            // the same freedom rules as auto-allocation — it only
            // replaces which resource of the required type is chosen.
            $explicitType = $resourceId !== null
                ? $this->appointments->resourceTypeOf($tenantId, $resourceId)
                : null;
            if ($resourceId !== null && $explicitType === null) {
                throw new TransitionException('RESOURCE_INVALID', 'That room is not available.', 422, ['resource_id' => 'Choose a valid room.']);
            }
            $explicitUsed = false;
            foreach ($this->appointments->serviceRequirements($tenantId, (int) $service['id']) as $requirement) {
                $resourceTypeId = (int) $requirement['resource_type_id'];
                $quantity = (int) $requirement['quantity'];
                $this->appointments->lockResourcesForType($tenantId, $branchId, $resourceTypeId);
                $useExplicit = $explicitType === $resourceTypeId;
                $allocatedId = $this->appointments->freeResourceForType(
                    $tenantId,
                    $branchId,
                    $resourceTypeId,
                    $this->dates->toDb($occupiedStart),
                    $this->dates->toDb($occupiedEnd),
                    (int) $localStart->format('N'),
                    $localStart->format('H:i:s'),
                    $localEnd->format('H:i:s'),
                    $quantity,
                    $localStart->format('Y-m-d'),
                    $useExplicit ? $resourceId : null,
                    $holdId,
                );
                if ($allocatedId === null) {
                    throw new TransitionException(
                        'RESOURCE_UNAVAILABLE',
                        $useExplicit ? 'That room is not free at that time.' : 'No room or equipment is free at that time.',
                        409,
                        $useExplicit ? ['resource_id' => 'Pick another room or time.'] : ['time' => 'Pick another time.'],
                    );
                }
                $explicitUsed = $explicitUsed || $useExplicit;
                $resourceAssignments[] = [
                    'resource_id' => $allocatedId,
                    'quantity' => $quantity,
                ];
            }
            if ($resourceId !== null && !$explicitUsed) {
                throw new TransitionException('RESOURCE_INVALID', 'That room is not required for this service.', 422, ['resource_id' => 'Choose a room this service uses.']);
            }

            $customerId = $this->upsertCustomer($tenantId, $customer, $actorType, $phoneVerified);
            // A customer cannot hold two overlapping live bookings; staff
            // bookings skip this (reception may book for several people
            // under one contact).
            if ($publicSource
                && $this->appointments->customerOverlaps(
                    $tenantId,
                    $customerId,
                    $this->dates->toDb($start),
                    $this->dates->toDb($end),
                ) > 0) {
                throw new TransitionException('ALREADY_BOOKED', 'You already have an appointment at that time.', 409, ['time' => 'Pick another time.']);
            }

            // Prices are tax-inclusive: the service price already contains
            // VAT and service charge. Snapshot the rates at booking time so
            // the till/receipt extraction matches what the customer saw.
            $itemTaxBp = $this->till->tenantTaxRateBp($tenantId);
            $itemTaxPerUnit = $this->till->extractLevies(
                $price,
                $itemTaxBp,
                $this->till->serviceChargeBp($tenantId),
            )['vat_minor'];

            $reference = $this->generateReference($tenantId);
            $uuid = $this->uuids->generate();
            $policyVersion = $this->appointments->policyVersionFor($tenantId, $branchId) ?? 1;
            $status = self::initialStatus($actorType, $policy);
            $paymentStatus = $price > 0 ? 'unpaid' : 'not_required';
            $manageToken = bin2hex(random_bytes(24));

            try {
                $appointmentId = $this->appointments->insertAppointment([
                    'uuid' => $uuid,
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'customer_id' => $customerId,
                    'reference' => $reference,
                    'manage_token_hash' => hash('sha256', $manageToken),
                    'idempotency_key' => $idempotencyKey,
                    'status' => $status,
                    'confirmed_at' => $status === 'confirmed' ? $this->dates->toDb($this->dates->now()) : null,
                    'payment_status' => $paymentStatus,
                    'booking_source' => $source,
                    'start_at' => $this->dates->toDb($start),
                    'end_at' => $this->dates->toDb($end),
                    'staff_id' => $staffId,
                    'customer_name_snapshot' => $customer['name'],
                    'customer_phone_snapshot' => $customer['phone'],
                    'customer_email_snapshot' => $customer['email'],
                    'locale' => $locale,
                    'total_amount_minor' => $price,
                    'deposit_amount_minor' => 0,
                    'currency' => $currency,
                    'policy_version' => $policyVersion,
                    'version' => 1,
                    'notes' => $customer['notes'],
                    // FK to users(id): only a tenant user is a user. API
                    // credentials, customers and the system are recorded on the
                    // status.created event (actor_type + actor_id) instead.
                    'created_by_user_id' => $actorType === 'tenant_user' ? $actorUserId : null,
                ]);
            } catch (\PDOException $e) {
                // Parallel retry with the same key won the race (unique
                // index on (tenant_id, idempotency_key)) — return theirs.
                if ($idempotencyKey !== null && $e->getCode() === '23000') {
                    $existing = $this->appointments->findByIdempotencyKey($tenantId, $idempotencyKey);
                    if ($existing !== null) {
                        return $this->result($existing, null, true);
                    }
                }
                throw $e;
            }

            $itemId = $this->appointments->insertItem([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'item_position' => 0,
                'service_id' => (int) $service['id'],
                'staff_id' => $staffId,
                'service_name_snapshot' => (string) $service['name'],
                'price_type' => $service['price_type'],
                'unit_amount_minor' => $price,
                'min_amount_minor' => $service['min_amount_minor'],
                'max_amount_minor' => $service['max_amount_minor'],
                'quantity' => 1,
                'duration_minutes' => $duration,
                'buffer_before_minutes' => $bufferBefore,
                'buffer_after_minutes' => $bufferAfter,
                'start_at' => $this->dates->toDb($start),
                'end_at' => $this->dates->toDb($end),
                'occupied_start_at' => $this->dates->toDb($occupiedStart),
                'occupied_end_at' => $this->dates->toDb($occupiedEnd),
                'tax_rate_bp' => $itemTaxBp,
                'tax_amount_minor' => $itemTaxPerUnit,
                'currency' => $currency,
                'policy_version' => $policyVersion,
            ]);

            foreach ($resourceAssignments as $assignment) {
                $this->appointments->insertResourceAllocation([
                    'tenant_id' => $tenantId,
                    'appointment_item_id' => $itemId,
                    'resource_id' => $assignment['resource_id'],
                    'quantity' => $assignment['quantity'],
                    'occupied_start_at' => $this->dates->toDb($occupiedStart),
                    'occupied_end_at' => $this->dates->toDb($occupiedEnd),
                ]);
            }

            if ($holdId !== null) {
                $this->holds->markConverted($tenantId, $holdId, (int) $appointmentId);
            }
            $this->appointments->insertEvent($tenantId, $appointmentId, 'status.created', $actorType, $actorUserId, null, $status);
            $this->events->onCreated($tenantId, (int) $appointmentId);

            return [
                'uuid' => $uuid,
                'reference' => $reference,
                'start_at' => $this->dates->toDb($start),
                'end_at' => $this->dates->toDb($end),
                'status' => $status,
                'manage_token' => $manageToken,
                'existing' => false,
            ];
        }, IsolationLevel::ReadCommitted);
    }

    /**
     * Idempotent replay for callers that validate the slot before create()
     * (the public booking controller): a legitimate retry would fail the
     * pre-check on its own booking, so they peek here first. The manage
     * token stays null on replay — only the hash is stored.
     *
     * @return array{uuid: string, reference: string, start_at: string, end_at: string, status: string, manage_token: ?string, existing: bool, staff_name: ?string, service_name: ?string}|null
     */
    public function existing(int $tenantId, ?string $idempotencyKey): ?array
    {
        if ($idempotencyKey === null) {
            return null;
        }
        $row = $this->appointments->findByIdempotencyKey($tenantId, $idempotencyKey);
        if ($row === null) {
            return null;
        }
        $item = $this->appointments->itemsFor($tenantId, (int) $row['id'])[0] ?? null;

        return $this->result($row, null, true) + [
            'staff_name' => $item !== null && $item['staff_name'] !== null ? (string) $item['staff_name'] : null,
            'service_name' => $item !== null ? (string) $item['service_name_snapshot'] : null,
        ];
    }

    /**
     * Staff and integrations book on the business's behalf, so their
     * bookings are confirmed at once. Customer bookings follow the policy:
     * auto-confirm (default) or manual approval (lands as pending).
     *
     * @param array<string, mixed> $policy
     */
    public static function initialStatus(string $actorType, array $policy): string
    {
        if ($actorType !== 'customer') {
            return 'confirmed';
        }
        return ($policy['confirmation_mode'] ?? 'auto') === 'manual' ? 'pending' : 'confirmed';
    }

    /**
     * Normalise an appointments row into the create() return shape.
     * @param array<string, mixed> $row
     * @return array{uuid: string, reference: string, start_at: string, end_at: string, status: string, manage_token: ?string, existing: bool}
     */
    private function result(array $row, ?string $manageToken, bool $existing): array
    {
        return [
            'uuid' => (string) $row['uuid'],
            'reference' => (string) $row['reference'],
            'start_at' => (string) $row['start_at'],
            'end_at' => (string) $row['end_at'],
            'status' => (string) $row['status'],
            'manage_token' => $manageToken,
            'existing' => $existing,
        ];
    }

    /**
     * Move an existing appointment to a new time (optionally a new staff
     * member / room) through the same availability protocol as create()
     * (docs I3): integrity checks always apply, policy checks apply unless
     * a staff actor passes appointments.override_policy, and every
     * occupancy/resource check excludes the appointment's own current
     * window. Reschedulable statuses: pending, confirmed.
     *
     * Customer actors (manage token) additionally obey
     * reschedule_cutoff_minutes and max_reschedules from booking_policies.
     *
     * @return array{uuid: string, start_at: string, end_at: string, status: string, version: int}
     */
    public function reschedule(
        int $tenantId,
        string $uuid,
        string $localDate,
        string $localTime,
        ?int $staffId = null,
        ?int $expectedVersion = null,
        ?int $actorUserId = null,
        string $actorType = 'tenant_user',
        bool $canOverridePolicy = false,
        ?int $resourceId = null,
    ): array {
        $appointment = $this->appointments->findByUuid($tenantId, $uuid);
        if ($appointment === null) {
            throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
        }
        if (!in_array((string) $appointment['status'], ['pending', 'confirmed'], true)) {
            throw new TransitionException('INVALID_TRANSITION', 'This appointment can no longer be rescheduled.', 409);
        }
        $item = $this->appointments->primaryItemFull($tenantId, (int) $appointment['id']);
        if ($item === null || $item['staff_id'] === null || $item['service_id'] === null) {
            throw new TransitionException('INVALID_TRANSITION', 'This appointment has no reschedulable service.', 409);
        }

        $isCustomer = $actorType === 'customer';
        $branchId = (int) $appointment['branch_id'];
        $branchTimezone = $this->branchTimezone($tenantId, $branchId);
        $serviceId = (int) $item['service_id'];
        $targetStaffId = $staffId ?? (int) $item['staff_id'];
        $duration = (int) $item['duration_minutes'];
        $bufferBefore = (int) $item['buffer_before_minutes'];
        $bufferAfter = (int) $item['buffer_after_minutes'];

        // Integrity — no permission bypasses these (mirrors create()).
        if (!$this->appointments->staffAssignedToBranch($tenantId, $targetStaffId, $branchId)) {
            throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not assigned to this branch.', 422, ['staff' => 'Choose staff assigned to this branch.']);
        }
        if (!$this->appointments->staffCanPerform($tenantId, $targetStaffId, $serviceId)) {
            throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member does not offer this service.', 422, ['staff' => 'Choose staff who offer this service.']);
        }

        $start = $this->dates->localToUtc($localDate, $localTime, $branchTimezone);
        if ($start === null) {
            throw new TransitionException('INVALID_TIME', 'That time does not exist.', 422, ['time' => 'Pick a valid time.']);
        }
        if ($start < $this->dates->now()) {
            throw new TransitionException('INVALID_TIME', 'That time has already passed.', 422, ['time' => 'Pick a future time.']);
        }

        $policy = $this->pages->policy($tenantId, $branchId) ?? [];
        $maxAdvance = $policy['max_advance_days'] ?? null;
        if ($maxAdvance !== null && ($isCustomer || !$canOverridePolicy)) {
            $latest = $this->dates->now()
                ->setTimezone(new \DateTimeZone($branchTimezone))
                ->modify('+' . (int) $maxAdvance . ' days');
            if ($localDate > $latest->format('Y-m-d')) {
                throw new TransitionException('INVALID_TIME', 'That date is too far ahead.', 422, ['date' => 'Pick a nearer date.']);
            }
        }
        $minNotice = (int) ($policy['min_notice_minutes'] ?? 30);
        if (($isCustomer || !$canOverridePolicy)
            && $start < $this->dates->now()->modify("+{$minNotice} minutes")) {
            throw new TransitionException('INVALID_TIME', 'That slot is too soon.', 422, ['time' => "This service needs at least {$minNotice} minutes of notice."]);
        }
        if ($isCustomer || !$canOverridePolicy) {
            $startLocal = $start->setTimezone(new \DateTimeZone($branchTimezone));
            $startMinute = (int) $startLocal->format('H') * 60 + (int) $startLocal->format('i');
            if (!$this->availability->fitsWorkingWindow(
                $tenantId,
                $branchId,
                $targetStaffId,
                $localDate,
                $startMinute,
                $duration,
                $branchTimezone,
            )) {
                throw new TransitionException('OUTSIDE_HOURS', 'That time is outside working hours or a break.', 422, ['time' => 'Pick a time inside working hours.']);
            }
        }

        $end = $start->modify('+' . $duration . ' minutes');
        $occupiedStart = $start->modify('-' . $bufferBefore . ' minutes');
        $occupiedEnd = $end->modify('+' . $bufferAfter . ' minutes');

        return $this->db->transaction(function () use (
            $tenantId,
            $branchTimezone,
            $targetStaffId,
            $serviceId,
            $item,
            $appointment,
            $isCustomer,
            $start,
            $end,
            $occupiedStart,
            $occupiedEnd,
            $localDate,
            $expectedVersion,
            $actorUserId,
            $actorType,
            $resourceId,
            $policy,
        ): array {
            $locked = $this->appointments->lockForUpdate($tenantId, (string) $appointment['uuid']);
            if ($locked === null) {
                throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
            }
            if (!in_array((string) $locked['status'], ['pending', 'confirmed'], true)) {
                throw new TransitionException('INVALID_TRANSITION', 'This appointment can no longer be rescheduled.', 409);
            }
            // Customer self-service cutoffs are checked against the locked
            // row — a parallel reschedule cannot slip past the count limit.
            if ($isCustomer) {
                $oldStart = new \DateTimeImmutable((string) $locked['start_at'], new \DateTimeZone('UTC'));
                if ($this->dates->now() >= $oldStart) {
                    throw new TransitionException('CUTOFF_PASSED', 'This appointment has already started — contact the business.', 409, ['time' => 'The appointment time has passed.']);
                }
                $cutoff = isset($policy['reschedule_cutoff_minutes']) ? (int) $policy['reschedule_cutoff_minutes'] : null;
                if ($cutoff !== null && $this->dates->now() > $oldStart->modify("-{$cutoff} minutes")) {
                    throw new TransitionException('CUTOFF_PASSED', 'It is too late to reschedule this appointment.', 409, ['time' => 'The reschedule deadline has passed — contact the business.']);
                }
                $maxReschedules = isset($policy['max_reschedules']) ? (int) $policy['max_reschedules'] : null;
                if ($maxReschedules !== null && (int) $locked['reschedule_count'] >= $maxReschedules) {
                    throw new TransitionException('RESCHEDULE_LIMIT', 'This appointment has been rescheduled the maximum number of times.', 409, ['time' => 'Reschedule limit reached — contact the business.']);
                }
            }
            if ($expectedVersion !== null && (int) $locked['version'] !== $expectedVersion) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }
            $appointmentId = (int) $locked['id'];
            $itemId = (int) $item['id'];

            $staff = $this->appointments->lockStaff($tenantId, $targetStaffId);
            if ($staff === null || $staff['status'] !== 'active') {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available.', 422, ['staff' => 'Choose available staff.']);
            }
            // Free this appointment's own resource allocations first so a
            // move within the same room does not count against itself.
            $this->appointments->deleteResourceAllocations($tenantId, $itemId);
            if ($this->appointments->staffOccupied(
                $tenantId,
                $targetStaffId,
                $this->dates->toDb($occupiedStart),
                $this->dates->toDb($occupiedEnd),
                $appointmentId,
            ) > 0) {
                throw new TransitionException('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
            }
            if ($this->appointments->staffUnavailableConflict(
                $tenantId,
                $targetStaffId,
                (int) $locked['branch_id'],
                $localDate,
                $this->dates->toDb($occupiedStart),
                $this->dates->toDb($occupiedEnd),
            ) > 0) {
                throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available at that time.', 409, ['time' => 'Pick another time.']);
            }
            if ($isCustomer && $locked['customer_id'] !== null
                && $this->appointments->customerOverlaps(
                    $tenantId,
                    (int) $locked['customer_id'],
                    $this->dates->toDb($start),
                    $this->dates->toDb($end),
                    $appointmentId,
                ) > 0) {
                throw new TransitionException('ALREADY_BOOKED', 'You already have an appointment at that time.', 409, ['time' => 'Pick another time.']);
            }

            $resourceAssignments = [];
            $tz = new \DateTimeZone($branchTimezone);
            $localStart = $occupiedStart->setTimezone($tz);
            $localEnd = $occupiedEnd->setTimezone($tz);
            $explicitType = $resourceId !== null
                ? $this->appointments->resourceTypeOf($tenantId, $resourceId)
                : null;
            if ($resourceId !== null && $explicitType === null) {
                throw new TransitionException('RESOURCE_INVALID', 'That room is not available.', 422, ['resource_id' => 'Choose a valid room.']);
            }
            $explicitUsed = false;
            foreach ($this->appointments->serviceRequirements($tenantId, $serviceId) as $requirement) {
                $resourceTypeId = (int) $requirement['resource_type_id'];
                $quantity = (int) $requirement['quantity'];
                $this->appointments->lockResourcesForType($tenantId, (int) $locked['branch_id'], $resourceTypeId);
                $useExplicit = $explicitType === $resourceTypeId;
                $allocatedId = $this->appointments->freeResourceForType(
                    $tenantId,
                    (int) $locked['branch_id'],
                    $resourceTypeId,
                    $this->dates->toDb($occupiedStart),
                    $this->dates->toDb($occupiedEnd),
                    (int) $localStart->format('N'),
                    $localStart->format('H:i:s'),
                    $localEnd->format('H:i:s'),
                    $quantity,
                    $localStart->format('Y-m-d'),
                    $useExplicit ? $resourceId : null,
                );
                if ($allocatedId === null) {
                    throw new TransitionException(
                        'RESOURCE_UNAVAILABLE',
                        $useExplicit ? 'That room is not free at that time.' : 'No room or equipment is free at that time.',
                        409,
                        $useExplicit ? ['resource_id' => 'Pick another room or time.'] : ['time' => 'Pick another time.'],
                    );
                }
                $explicitUsed = $explicitUsed || $useExplicit;
                $resourceAssignments[] = [
                    'resource_id' => $allocatedId,
                    'quantity' => $quantity,
                ];
            }
            if ($resourceId !== null && !$explicitUsed) {
                throw new TransitionException('RESOURCE_INVALID', 'That room is not required for this service.', 422, ['resource_id' => 'Choose a room this service uses.']);
            }

            $newVersion = $this->appointments->updateSchedule(
                $tenantId,
                $appointmentId,
                (int) $locked['version'],
                $this->dates->toDb($start),
                $this->dates->toDb($end),
                $targetStaffId,
            );
            if ($newVersion === null) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }
            $this->appointments->updateItemSchedule(
                $tenantId,
                $itemId,
                $targetStaffId,
                $this->dates->toDb($start),
                $this->dates->toDb($end),
                $this->dates->toDb($occupiedStart),
                $this->dates->toDb($occupiedEnd),
            );
            foreach ($resourceAssignments as $assignment) {
                $this->appointments->insertResourceAllocation([
                    'tenant_id' => $tenantId,
                    'appointment_item_id' => $itemId,
                    'resource_id' => $assignment['resource_id'],
                    'quantity' => $assignment['quantity'],
                    'occupied_start_at' => $this->dates->toDb($occupiedStart),
                    'occupied_end_at' => $this->dates->toDb($occupiedEnd),
                ]);
            }

            $this->appointments->insertEvent(
                $tenantId,
                $appointmentId,
                'rescheduled',
                $actorType,
                $actorUserId,
                (string) $locked['status'],
                (string) $locked['status'],
                null,
                [
                    'from_start' => (string) $appointment['start_at'],
                    'to_start' => $this->dates->toDb($start),
                    'from_staff' => (int) $item['staff_id'],
                    'to_staff' => $targetStaffId,
                ],
            );
            $this->events->onTransitioned($tenantId, $appointmentId, 'rescheduled');

            return [
                'uuid' => (string) $locked['uuid'],
                'start_at' => $this->dates->toDb($start),
                'end_at' => $this->dates->toDb($end),
                'status' => (string) $locked['status'],
                'version' => $newVersion,
            ];
        }, IsolationLevel::ReadCommitted);
    }

    /** Branch timezone for reschedule (the appointment keeps its branch). */
    private function branchTimezone(int $tenantId, int $branchId): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT b.timezone, t.timezone AS tenant_tz
               FROM branches b JOIN tenants t ON t.id = b.tenant_id
              WHERE b.tenant_id = :t AND b.id = :b LIMIT 1',
        );
        $stmt->execute(['t' => $tenantId, 'b' => $branchId]);
        $row = $stmt->fetch();

        return (string) (($row['timezone'] ?? '') ?: ($row['tenant_tz'] ?? 'Africa/Nairobi'));
    }

    /**
     * Find-or-create the customer (update.md 1.8).
     *
     * A phone number alone is not proof of identity on a public form: anyone
     * can type someone else's number. So an online booking joins an existing
     * customer only when the phone was verified by SMS code, or when the
     * name or e-mail also matches. Otherwise it becomes a separate customer
     * without the phone match key; it then shows up as a possible duplicate
     * (same phone) for staff to merge. Staff and integration bookings keep
     * matching on phone: the business vouches for those.
     *
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     */
    private function upsertCustomer(int $tenantId, array $customer, string $actorType, bool $phoneVerified = false): int
    {
        $pdo = $this->db->pdo();
        $phone = $customer['phone'];
        $claimKey = true;
        if ($phone !== null && $phone !== '') {
            $existing = $this->appointments->findCustomerByPhone($tenantId, $phone);
            if ($existing !== null) {
                if ($actorType !== 'customer' || $phoneVerified || $this->sameCustomer($tenantId, (int) $existing['id'], $customer)) {
                    return (int) $existing['id'];
                }
                $claimKey = false; // provisional: never steals the phone key
            }
        }

        [$first, $last] = $this->splitName($customer['name']);
        $stmt = $pdo->prepare(
            'INSERT INTO customers (uuid, tenant_id, first_name, last_name, phone_e164, email)
             VALUES (:u, :t, :f, :l, :p, :e)',
        );
        $uuid = $this->uuids->generate();
        $stmt->execute(['u' => $uuid, 't' => $tenantId, 'f' => $first, 'l' => $last, 'p' => $phone, 'e' => $customer['email']]);
        $customerId = (int) $pdo->lastInsertId();

        if ($phone !== null && $phone !== '' && $claimKey) {
            try {
                $pdo->prepare(
                    "INSERT INTO customer_match_keys (tenant_id, match_type, match_value, customer_id)
                     VALUES (:t, 'phone', :v, :c)",
                )->execute(['t' => $tenantId, 'v' => $phone, 'c' => $customerId]);
            } catch (\PDOException $e) {
                // The match-key PK is the identity race arbiter (§20): under
                // READ COMMITTED a duplicate error means the winner already
                // committed, so its row is now visible — drop our orphan and
                // converge on it instead of failing the booking.
                if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                    throw $e;
                }
                $pdo->prepare('DELETE FROM customers WHERE tenant_id = :t AND id = :c')
                    ->execute(['t' => $tenantId, 'c' => $customerId]);
                $winner = $this->appointments->findCustomerByPhone($tenantId, $phone);
                if ($winner === null) {
                    throw $e;
                }
                return (int) $winner['id'];
            }
        }

        return $customerId;
    }

    /**
     * Unverified online booking vs an existing customer with the same phone:
     * same person when the e-mail matches, or the first name does.
     *
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     */
    private function sameCustomer(int $tenantId, int $customerId, array $customer): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT first_name, email FROM customers WHERE tenant_id = :t AND id = :c');
        $stmt->execute(['t' => $tenantId, 'c' => $customerId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return false;
        }
        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && $customer['email'] !== null && hash_equals($email, mb_strtolower($customer['email']))) {
            return true;
        }
        $given = mb_strtolower($this->splitName($customer['name'])[0]);
        $stored = mb_strtolower(trim((string) $row['first_name']));

        return $given !== '' && $given === $stored;
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? 'Customer', $parts[1] ?? $parts[0] ?? 'Customer'];
    }

    private function generateReference(int $tenantId): string
    {
        $pdo = $this->db->pdo();
        $length = strlen(self::REFERENCE_ALPHABET);
        for ($attempt = 0; $attempt < 8; $attempt += 1) {
            $reference = '';
            for ($i = 0; $i < 8; $i += 1) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, $length - 1)];
            }
            $stmt = $pdo->prepare('SELECT 1 FROM appointments WHERE tenant_id = :t AND reference = :r LIMIT 1');
            $stmt->execute(['t' => $tenantId, 'r' => $reference]);
            if ($stmt->fetchColumn() === false) {
                return $reference;
            }
        }

        throw new \RuntimeException('Could not allocate a booking reference.');
    }
}
