<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Database\Database;

/**
 * `booking:integrity-check` — the BookingIntegrityCheck required by
 * booking-engine.md §20. Scans every tenant for structural violations the
 * booking protocol promises cannot exist:
 *
 *   staff_overlap      two live appointments occupy the same staff member
 *                      over an overlapping buffer-inclusive window
 *   resource_capacity  a resource's live allocations exceed its capacity
 *                      at some instant
 *   item_window_desync an appointment item's window or buffer-inclusive
 *                      occupied range is out of sync with its appointment
 *   live_without_item  a live appointment has no items or no staff
 *   stale_hold         a booking hold still 'active' past its expiry
 *
 * Exit 0 = zero violations, 1 = violations found (each line is
 * tenant:check:detail). Run after concurrency suites and in cron.
 */
final class IntegrityCheckCommand implements Command
{
    private const BLOCKING = "'pending','confirmed','checked_in','in_service'";

    public function __construct(private readonly Database $db) {}

    public function name(): string
    {
        return 'booking:integrity-check';
    }

    public function description(): string
    {
        return 'Verify no double-bookings, over-allocated resources or desynced windows exist';
    }

    /** @param list<string> $args */
    public function run(array $args, Output $output): int
    {
        $violations = 0;
        $violations += $this->check(
            $output,
            'staff_overlap',
            "SELECT DISTINCT a.tenant_id, ai.staff_id, ai.appointment_id, ai2.appointment_id
               FROM appointment_items ai
               JOIN appointments a ON a.tenant_id = ai.tenant_id AND a.id = ai.appointment_id
               JOIN appointment_items ai2
                 ON ai2.tenant_id = ai.tenant_id AND ai2.staff_id = ai.staff_id
                AND ai2.occupied_start_at < ai.occupied_end_at
                AND ai2.occupied_end_at > ai.occupied_start_at
                AND ai2.appointment_id <> ai.appointment_id
               JOIN appointments a2 ON a2.tenant_id = ai2.tenant_id AND a2.id = ai2.appointment_id
              WHERE ai.staff_id IS NOT NULL
                AND a.status IN (" . self::BLOCKING . ")
                AND a2.status IN (" . self::BLOCKING . ")
                AND ai.appointment_id < ai2.appointment_id",
        );

        // Over-capacity is evaluated at every allocation's occupied_start —
        // the max concurrent load on a resource is always reached at one of
        // its allocations' start instants.
        $violations += $this->check(
            $output,
            'resource_capacity',
            "SELECT x.resource_id, x.occupied_start_at, SUM(x.qty) AS concurrent_qty, r.capacity
               FROM (
                 SELECT ar.tenant_id, ar.resource_id, ar.occupied_start_at, ar2.quantity AS qty
                   FROM appointment_resources ar
                   JOIN appointment_items ai ON ai.tenant_id = ar.tenant_id AND ai.id = ar.appointment_item_id
                   JOIN appointments a ON a.tenant_id = ar.tenant_id AND a.id = ai.appointment_id
                   JOIN appointment_resources ar2
                     ON ar2.tenant_id = ar.tenant_id AND ar2.resource_id = ar.resource_id
                    AND ar2.occupied_start_at <= ar.occupied_start_at
                    AND ar2.occupied_end_at > ar.occupied_start_at
                   JOIN appointment_items ai2 ON ai2.tenant_id = ar2.tenant_id AND ai2.id = ar2.appointment_item_id
                   JOIN appointments a2 ON a2.tenant_id = ar2.tenant_id AND a2.id = ai2.appointment_id
                  WHERE a.status IN (" . self::BLOCKING . ")
                    AND a2.status IN (" . self::BLOCKING . ")
               ) x
               JOIN resources r ON r.tenant_id = x.tenant_id AND r.id = x.resource_id
              GROUP BY x.tenant_id, x.resource_id, x.occupied_start_at, r.capacity
             HAVING concurrent_qty > r.capacity",
        );

        $violations += $this->check(
            $output,
            'item_window_desync',
            "SELECT ai.tenant_id, ai.appointment_id, ai.id
               FROM appointment_items ai
               JOIN appointments a ON a.tenant_id = ai.tenant_id AND a.id = ai.appointment_id
              WHERE ai.start_at <> a.start_at OR ai.end_at <> a.end_at
                 OR ai.occupied_start_at <> ai.start_at - INTERVAL ai.buffer_before_minutes MINUTE
                 OR ai.occupied_end_at <> ai.end_at + INTERVAL ai.buffer_after_minutes MINUTE",
        );

        $violations += $this->check(
            $output,
            'live_without_item',
            "SELECT a.tenant_id, a.id, a.uuid
               FROM appointments a
              WHERE a.status IN (" . self::BLOCKING . ")
                AND (NOT EXISTS (SELECT 1 FROM appointment_items ai
                                   WHERE ai.tenant_id = a.tenant_id AND ai.appointment_id = a.id)
                     OR a.staff_id IS NULL)",
        );

        $violations += $this->check(
            $output,
            'stale_hold',
            "SELECT tenant_id, id, token_hash FROM booking_holds
              WHERE status = 'active' AND expires_at <= UTC_TIMESTAMP()",
        );

        if ($violations === 0) {
            $output->success('Zero violations — the booking protocol is intact.');
            return 0;
        }
        $output->error("{$violations} violation(s) found.");
        return 1;
    }

    /** Run a violation query; print each row as tenant:check:detail. */
    private function check(Output $output, string $name, string $sql): int
    {
        $stmt = $this->db->pdo()->query($sql);
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        foreach ($rows as $row) {
            $detail = implode(' ', array_map(
                static fn($v) => (string) $v,
                array_filter($row, static fn($k) => $k !== 'tenant_id', ARRAY_FILTER_USE_KEY),
            ));
            $output->warn("  {$row['tenant_id']}:{$name}: {$detail}");
        }

        return count($rows);
    }
}
