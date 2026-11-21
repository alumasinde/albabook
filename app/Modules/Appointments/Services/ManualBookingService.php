<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Services;

use App\Core\Database\Database;
use App\Core\Database\IsolationLevel;
use App\Modules\Appointments\Repositories\AppointmentRepository;
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
        private readonly DateTimeService $dates,
        private readonly UuidFactory $uuids,
    ) {}

    /**
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     * @return array{uuid: string, reference: string, start_at: string, end_at: string, status: string}
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
    ): array {
        $service = $this->appointments->serviceFor($tenantId, $serviceId, $branchId);
        if ($service === null || ($source === 'public_page' && !$service['is_bookable_online'])) {
            throw new TransitionException('SERVICE_UNAVAILABLE', 'That service is not bookable.', 422, ['service' => 'Choose a bookable service.']);
        }
        if (array_key_exists('branch_bookable', $service) && $service['branch_bookable'] !== null && !$service['branch_bookable']) {
            throw new TransitionException('SERVICE_UNAVAILABLE', 'That service is not bookable at this branch.', 422, ['service' => 'Choose a bookable service.']);
        }

        $duration = (int) $service['duration_minutes'];
        $bufferBefore = (int) $service['buffer_before_minutes'];
        $bufferAfter = (int) $service['buffer_after_minutes'];
        $price = $service['branch_price'] ?? $service['price_amount_minor'] ?? $service['min_amount_minor'] ?? 0;

        $start = $this->dates->localToUtc($localDate, $localTime, $branchTimezone);
        if ($start === null) {
            throw new TransitionException('INVALID_TIME', 'That time does not exist.', 422, ['time' => 'Pick a valid time.']);
        }
        if ($start < $this->dates->now() && $source !== 'dashboard') {
            throw new TransitionException('INVALID_TIME', 'That time has already passed.', 422, ['time' => 'Pick a future time.']);
        }
        $end = $start->modify('+' . $duration . ' minutes');
        $occupiedStart = $start->modify('-' . $bufferBefore . ' minutes');
        $occupiedEnd = $end->modify('+' . $bufferAfter . ' minutes');

        return $this->db->transaction(function () use (
            $tenantId, $branchId, $branchTimezone, $staffId, $service, $duration, $bufferBefore, $bufferAfter,
            $price, $start, $end, $occupiedStart, $occupiedEnd,
            $customer, $source, $locale, $currency, $actorUserId, $actorType,
        ): array {
            $staff = $this->appointments->lockStaff($tenantId, $staffId);
                if ($staff === null || $staff['status'] !== 'active') {
                    throw new TransitionException('STAFF_UNAVAILABLE', 'That staff member is not available.', 422, ['staff' => 'Choose available staff.']);
                }
                $conflicts = $this->appointments->staffOccupied(
                    $tenantId,
                    $staffId,
                    $this->dates->toDb($occupiedStart),
                    $this->dates->toDb($occupiedEnd),
                );
                if ($conflicts > 0) {
                    throw new TransitionException('SLOT_UNAVAILABLE', 'That time is no longer available.', 409, ['time' => 'Pick another time.']);
                }

                $resourceAssignments = [];
                $tz = new \DateTimeZone($branchTimezone);
                $localStart = $occupiedStart->setTimezone($tz);
                $localEnd = $occupiedEnd->setTimezone($tz);
                foreach ($this->appointments->serviceRequirements($tenantId, (int) $service['id']) as $requirement) {
                    $resourceId = $this->appointments->freeResourceForType(
                        $tenantId,
                        $branchId,
                        (int) $requirement['resource_type_id'],
                        $this->dates->toDb($occupiedStart),
                        $this->dates->toDb($occupiedEnd),
                        (int) $localStart->format('N'),
                        $localStart->format('H:i:s'),
                        $localEnd->format('H:i:s'),
                    );
                    if ($resourceId === null) {
                        throw new TransitionException('RESOURCE_UNAVAILABLE', 'No room or equipment is free at that time.', 409, ['time' => 'Pick another time.']);
                    }
                    $resourceAssignments[] = [
                        'resource_id' => $resourceId,
                        'quantity' => (int) $requirement['quantity'],
                    ];
                }

            $customerId = $this->upsertCustomer($tenantId, $customer, $actorType);

            $reference = $this->generateReference($tenantId);
            $uuid = $this->uuids->generate();
            $policyVersion = $this->appointments->policyVersionFor($tenantId, $branchId) ?? 1;
            $status = 'pending';
            $paymentStatus = $price > 0 ? 'unpaid' : 'not_required';

            $appointmentId = $this->appointments->insertAppointment([
                'uuid' => $uuid,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'reference' => $reference,
                'status' => $status,
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
                'created_by_user_id' => $actorUserId,
            ]);

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
                'tax_rate_bp' => 0,
                'tax_amount_minor' => 0,
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

            $this->appointments->insertEvent($tenantId, $appointmentId, 'status.created', $actorType, $actorUserId, null, $status);

            return [
                'uuid' => $uuid,
                'reference' => $reference,
                'start_at' => $this->dates->toDb($start),
                'end_at' => $this->dates->toDb($end),
                'status' => $status,
            ];
        }, IsolationLevel::ReadCommitted);
    }

    /**
     * Upsert by phone match key (D-018 lite: phone match is the MVP policy).
     * @param array{name: string, phone: ?string, email: ?string, notes: ?string} $customer
     */
    private function upsertCustomer(int $tenantId, array $customer, string $actorType): int
    {
        $pdo = $this->db->pdo();
        $phone = $customer['phone'];
        if ($phone !== null && $phone !== '') {
            $existing = $this->appointments->findCustomerByPhone($tenantId, $phone);
            if ($existing !== null) {
                return (int) $existing['id'];
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

        if ($phone !== null && $phone !== '') {
            $pdo->prepare(
                "INSERT INTO customer_match_keys (tenant_id, match_type, match_value, customer_id)
                 VALUES (:t, 'phone', :v, :c)",
            )->execute(['t' => $tenantId, 'v' => $phone, 'c' => $customerId]);
        }

        return $customerId;
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
            $bytes = random_bytes(8);
            for ($i = 0; $i < 8; $i += 1) {
                $reference .= self::REFERENCE_ALPHABET[ord($bytes[$i]) % $length];
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
