<?php

declare(strict_types=1);

namespace App\Modules\Appointments\Services;

use App\Core\Database\Database;
use App\Modules\Appointments\Repositories\AppointmentRepository;
use App\Modules\Notifications\Services\AppointmentEventService;
use App\Shared\Time\DateTimeService;

/**
 * Appointment status transitions. The graph mirrors appointment_statuses
 * (000_reference_data.sql); permissions are checked by the caller.
 */
final class AppointmentTransitionService
{
    /** @var array<string, list<string>> */
    private const GRAPH = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['checked_in', 'cancelled', 'no_show', 'completed'],
        'checked_in' => ['in_service', 'completed', 'cancelled', 'no_show'],
        'in_service' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'no_show' => [],
        'rejected' => [],
        'expired' => [],
    ];

    /** Permission required per target status. */
    private const PERMISSION = [
        'confirmed' => 'appointments.confirm',
        'checked_in' => 'appointments.update',
        'in_service' => 'appointments.update',
        'completed' => 'appointments.update',
        'cancelled' => 'appointments.cancel',
        'no_show' => 'appointments.update',
    ];

    /** Transitions that mean "the visit is on": gated to a window around
     * the appointment's scheduled time so staff can't check in or complete
     * visits that haven't started or have long passed. */
    private const VISIT_WINDOW = ['checked_in', 'in_service', 'completed'];

    public function __construct(
        private readonly Database $db,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentEventService $events,
        private readonly DateTimeService $dates,
    ) {}

    /** @return list<string> */
    public static function allowed(string $status): array
    {
        return self::GRAPH[$status] ?? [];
    }

    public static function permissionFor(string $to): ?string
    {
        return self::PERMISSION[$to] ?? null;
    }

    /**
     * Time gate for visit transitions. Check-in and service start must fall
     * inside [start − early, end + late]; completion can't precede the
     * early window but may happen late (closing a forgotten ticket is
     * legitimate); a no-show is only meaningful after the start plus the
     * grace period. Returns [code, message] or null when allowed.
     * @param array{early_minutes: int, late_minutes: int, no_show_grace_minutes: int} $prefs
     * @return array{0: string, 1: string}|null
     */
    public function timingError(string $to, string $startAt, string $endAt, array $prefs): ?array
    {
        $now = $this->dates->now()->getTimestamp();
        $start = $this->dates->fromDb($startAt)->getTimestamp();
        $end = $this->dates->fromDb($endAt)->getTimestamp();
        $early = $prefs['early_minutes'] * 60;
        $late = $prefs['late_minutes'] * 60;

        if ($to === 'no_show') {
            return $now < $start + $prefs['no_show_grace_minutes'] * 60
                ? ['TOO_EARLY', 'A no-show can only be marked after the scheduled start time has passed.']
                : null;
        }
        if (!in_array($to, self::VISIT_WINDOW, true)) {
            return null;
        }
        if ($now < $start - $early) {
            $label = $to === 'completed' ? 'completed' : ($to === 'in_service' ? 'started' : 'checked in');

            return ['TOO_EARLY', sprintf(
                'Too early — this appointment can only be %s from %d minutes before its start time.',
                $label,
                $prefs['early_minutes'],
            )];
        }
        if ($to !== 'completed' && $now > $end + $late) {
            $label = $to === 'in_service' ? 'started' : 'checked in';

            return ['TOO_LATE', sprintf(
                'This appointment ended more than %d minutes ago and can no longer be %s.',
                $prefs['late_minutes'],
                $label,
            )];
        }

        return null;
    }

    /**
     * Apply a transition under a row lock; throws TransitionException with an
     * API error code on any rule violation.
     *
     * @return array<string, mixed> updated appointment row
     */
    public function transition(int $tenantId, string $uuid, string $to, ?int $expectedVersion, ?int $actorUserId, string $actorType = 'tenant_user', ?string $reason = null): array
    {
        if (!array_key_exists($to, self::PERMISSION)) {
            throw new TransitionException('INVALID_TRANSITION', 'Unknown transition target.', 400);
        }

        return $this->db->transaction(function () use ($tenantId, $uuid, $to, $expectedVersion, $actorUserId, $actorType, $reason): array {
            $row = $this->appointments->lockForUpdate($tenantId, $uuid);
            if ($row === null) {
                throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
            }
            $from = (string) $row['status'];
            if (!in_array($to, $this->allowed($from), true)) {
                throw new TransitionException('INVALID_TRANSITION', sprintf('Cannot move an appointment from %s to %s.', $from, $to), 409);
            }
            if ($expectedVersion !== null && (int) $row['version'] !== $expectedVersion) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }

            $issue = $this->timingError(
                $to,
                (string) $row['start_at'],
                (string) $row['end_at'],
                $this->appointments->visitWindowPrefs($tenantId),
            );
            if ($issue !== null) {
                throw new TransitionException($issue[0], $issue[1], 409);
            }

            $newVersion = $this->appointments->updateStatus($tenantId, (int) $row['id'], $to, (int) $row['version'], [
                'cancelled_by' => $actorType === 'customer' ? 'customer' : 'business',
                'reason' => $reason,
            ]);
            if ($newVersion === null) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }

            $this->appointments->insertEvent($tenantId, (int) $row['id'], 'status.' . $to, $actorType, $actorUserId, $from, $to, $reason);
            $this->events->onTransitioned($tenantId, (int) $row['id'], $to);
            $row['status'] = $to;
            $row['version'] = $newVersion;

            return $row;
        });
    }
}
