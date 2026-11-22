<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Command;
use App\Console\Output;
use App\Core\Database\Database;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Shared\Time\DateTimeService;

/**
 * Night audit (`audit:daily`): reconciles the previous business day per
 * tenant. Appointments that ended before today's local midnight but still
 * sit in a live status are stale — they block nothing anymore but never
 * got an outcome.
 *
 * Report-only by default. With --apply:
 *   pending   → expired   (never confirmed, slot lapsed)
 *   confirmed → no_show   (guest never checked in)
 *   checked_in → flagged only (staff must resolve — money/till may be open)
 *   in_service → flagged only (a ticket may still be open at the till)
 *
 * Run from cron after each tenant's day close, e.g. hourly.
 */
final class AuditDailyCommand implements Command
{
    public function __construct(
        private readonly Database $db,
        private readonly DateTimeService $dates,
        private readonly AppointmentEventService $events,
    ) {}

    public function name(): string
    {
        return 'audit:daily';
    }

    public function description(): string
    {
        return 'Night-audit reconciliation of stale appointments [--apply to transition]';
    }

    /** @param list<string> $args */
    public function run(array $args, Output $output): int
    {
        $apply = in_array('--apply', $args, true);
        $now = $this->dates->now();
        $totalStale = 0;
        $totalApplied = 0;

        $tenantsStmt = $this->db->pdo()
            ->query('SELECT id, name, timezone FROM tenants ORDER BY id');
        $tenants = $tenantsStmt === false ? [] : $tenantsStmt->fetchAll();

        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['id'];
            $tz = (string) ($tenant['timezone'] ?: 'UTC');
            try {
                $boundary = $now->setTimezone(new \DateTimeZone($tz))->setTime(0, 0)
                    ->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $output->line("  {$tenant['name']}: invalid timezone {$tz} — skipped");
                continue;
            }
            $boundaryDb = $this->dates->toDb($boundary);

            $stmt = $this->db->pdo()->prepare(
                "SELECT id, uuid, status, version FROM appointments
                 WHERE tenant_id = :t AND end_at < :boundary
                   AND status IN ('pending','confirmed','checked_in','in_service')
                 ORDER BY end_at",
            );
            $stmt->execute(['t' => $tenantId, 'boundary' => $boundaryDb]);
            /** @var list<array{id: int, uuid: string, status: string, version: int}> $stale */
            $stale = array_values($stmt->fetchAll());
            if ($stale === []) {
                continue;
            }
            $totalStale += count($stale);

            $counts = [];
            foreach ($stale as $row) {
                $counts[$row['status']] = ($counts[$row['status']] ?? 0) + 1;
            }
            $summary = implode(', ', array_map(
                static fn(string $k, int $v): string => "{$k}: {$v}",
                array_keys($counts),
                $counts,
            ));
            $output->line("  {$tenant['name']} (#{$tenantId}): " . count($stale) . " stale ({$summary})");

            if (!$apply) {
                continue;
            }
            foreach ($stale as $row) {
                $to = match ($row['status']) {
                    'pending' => 'expired',
                    'confirmed' => 'no_show',
                    default => null,
                };
                if ($to === null) {
                    continue; // checked_in / in_service need a human — till may be open
                }
                // One transaction per appointment: status, history row and
                // side effects (customer notice, appointment.* outbox event,
                // reminder cancellation) land together or not at all.
                $applied = $this->db->transaction(function () use ($tenantId, $row, $to): bool {
                    $newVersion = $this->appointmentsRepoUpdate($tenantId, (int) $row['id'], $to, (int) $row['version']);
                    if ($newVersion === null) {
                        return false; // raced with a staff action — next run picks it up
                    }
                    $this->db->pdo()->prepare(
                        'INSERT INTO appointment_events (tenant_id, appointment_id, event_type, actor_type, actor_id, from_status, to_status, reason)
                         VALUES (:t, :a, :type, :actor, NULL, :from, :to, :reason)',
                    )->execute([
                        't' => $tenantId,
                        'a' => (int) $row['id'],
                        'type' => 'status.' . $to,
                        'actor' => 'system',
                        'from' => $row['status'],
                        'to' => $to,
                        'reason' => 'night audit',
                    ]);
                    $this->events->onTransitioned($tenantId, (int) $row['id'], $to);

                    return true;
                });
                if ($applied) {
                    $totalApplied++;
                }
            }
        }

        $output->line($totalStale === 0
            ? 'Night audit clean — no stale appointments.'
            : "Found {$totalStale} stale appointment(s)" . ($apply ? ", transitioned {$totalApplied}." : ' — re-run with --apply to transition pending→expired and confirmed→no_show.'));

        return 0;
    }

    private function appointmentsRepoUpdate(int $tenantId, int $id, string $status, int $expectedVersion): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE appointments SET status = :s, version = version + 1
             WHERE tenant_id = :t AND id = :id AND version = :v',
        );
        $stmt->execute(['s' => $status, 't' => $tenantId, 'id' => $id, 'v' => $expectedVersion]);

        return $stmt->rowCount() === 1 ? $expectedVersion + 1 : null;
    }
}
