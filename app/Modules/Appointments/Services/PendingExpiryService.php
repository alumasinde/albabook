<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Services;

use App\Core\Database\Database;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Modules\Settings\Support\BookingRules;

/**
 * Expires booking requests nobody approved (manual confirmation mode), so
 * an unanswered request does not hold the slot all day.
 *
 * A pending appointment expires when either:
 *  - it has waited longer than pending_expiry_minutes (branch row, then the
 *    tenant row, then BookingRules::DEFAULT_PENDING_EXPIRY), or
 *  - its start time has arrived.
 *
 * Each expiry runs in its own transaction with a version check — a staff
 * member confirming at the same moment wins — and emits the usual status
 * history row and appointment_expired customer notice.
 */
final class PendingExpiryService
{
    public function __construct(
        private readonly Database $db,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentEventService $events,
    ) {}

    /** @return int number of requests expired */
    public function sweep(int $limit = 100): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT a.id, a.tenant_id, a.version
               FROM appointments a
               LEFT JOIN booking_policies bb ON bb.tenant_id = a.tenant_id AND bb.branch_scope = a.branch_id
               LEFT JOIN booking_policies bt ON bt.tenant_id = a.tenant_id AND bt.branch_scope = 0
              WHERE a.status = \'pending\'
                AND (a.start_at <= UTC_TIMESTAMP()
                     OR a.created_at <= UTC_TIMESTAMP() - INTERVAL
                        COALESCE(bb.pending_expiry_minutes, bt.pending_expiry_minutes, ' . BookingRules::DEFAULT_PENDING_EXPIRY . ') MINUTE)
              ORDER BY a.start_at
              LIMIT ' . max(1, $limit),
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $expired = 0;
        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $id = (int) $row['id'];
            $done = $this->db->transaction(function () use ($tenantId, $id, $row): bool {
                $newVersion = $this->appointments->updateStatus($tenantId, $id, 'expired', (int) $row['version']);
                if ($newVersion === null) {
                    return false; // confirmed/declined meanwhile
                }
                $this->appointments->insertEvent(
                    $tenantId, $id, 'status.expired', 'system', null, 'pending', 'expired', 'not approved in time',
                );
                $this->events->onTransitioned($tenantId, $id, 'expired');

                return true;
            });
            if ($done) {
                $expired++;
            }
        }

        return $expired;
    }
}
