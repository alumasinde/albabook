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

    /** Structured cancellation reasons captured in the cancel form; reported
     *  in Reports → No-shows. Free-text `reason` stays optional detail. */
    public const CANCEL_REASON_CODES = [
        'illness', 'schedule_conflict', 'price',
        'found_alternative', 'no_longer_needed', 'other',
    ];

    /** Edges a tenant can opt into via visit policy (tenant_settings
     * .preferences). The base GRAPH is the platform ceiling — policies can
     * only unlock edges declared here, never invent new ones. */
    private const POLICY_EDGES = [
        // Salons skip the waiting-room step: confirmed → in_service when
        // require_check_in = false (default true keeps the clinic flow).
        'confirmed' => ['in_service' => 'require_check_in'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentEventService $events,
        private readonly DateTimeService $dates,
    ) {}

    /**
     * @param array{require_check_in?: bool} $policy
     * @return list<string>
     */
    public static function allowed(string $status, array $policy = []): array
    {
        $targets = self::GRAPH[$status] ?? [];
        foreach (self::POLICY_EDGES[$status] ?? [] as $to => $key) {
            // require_check_in = false unlocks the edge. Inserted right after
            // checked_in so the flow still reads check in → start → complete.
            if (array_key_exists($key, $policy) && $policy[$key] === false && !in_array($to, $targets, true)) {
                $pos = array_search('checked_in', $targets, true);
                array_splice($targets, $pos === false ? 0 : $pos + 1, 0, [$to]);
            }
        }

        return $targets;
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
    /**
     * Apply a transition under a row lock; throws TransitionException with an
     * API error code on any rule violation.
     *
     * Tenants running visit_window_mode=warn get a soft guardrail instead of
     * a hard block: an out-of-window transition answers TIMING_WARNING with a
     * confirmable flag, and the client retries with $force=true. The override
     * is recorded on the transition event so the audit trail shows the window
     * was deliberately crossed.
     *
     * @return array<string, mixed> updated appointment row
     */
    public function transition(int $tenantId, string $uuid, string $to, ?int $expectedVersion, ?int $actorUserId, string $actorType = 'tenant_user', ?string $reason = null, ?string $cancelCode = null, bool $force = false): array
    {
        if (!array_key_exists($to, self::PERMISSION)) {
            throw new TransitionException('INVALID_TRANSITION', 'Unknown transition target.', 400);
        }
        if ($to === 'cancelled' && $cancelCode !== null && !in_array($cancelCode, self::CANCEL_REASON_CODES, true)) {
            throw new TransitionException('VALIDATION', 'Choose a valid cancellation reason.', 422, ['cancel_code' => 'Unknown reason.']);
        }

        return $this->db->transaction(function () use ($tenantId, $uuid, $to, $expectedVersion, $actorUserId, $actorType, $reason, $cancelCode, $force): array {
            $row = $this->appointments->lockForUpdate($tenantId, $uuid);
            if ($row === null) {
                throw new TransitionException('NOT_FOUND', 'Appointment not found.', 404);
            }
            $prefs = $this->appointments->visitWindowPrefs($tenantId);
            $from = (string) $row['status'];
            if (!in_array($to, self::allowed($from, $prefs), true)) {
                throw new TransitionException('INVALID_TRANSITION', sprintf('Cannot move an appointment from %s to %s.', $from, $to), 409);
            }
            if ($expectedVersion !== null && (int) $row['version'] !== $expectedVersion) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }

            $override = null;
            $issue = $this->timingError($to, (string) $row['start_at'], (string) $row['end_at'], $prefs);
            if ($issue !== null) {
                if ($prefs['window_mode'] === 'warn' && $force) {
                    $override = $issue[1];
                } elseif ($prefs['window_mode'] === 'warn') {
                    throw new TransitionException('TIMING_WARNING', $issue[1], 409, ['confirmable' => true]);
                } else {
                    throw new TransitionException($issue[0], $issue[1], 409);
                }
            }

            $newVersion = $this->appointments->updateStatus($tenantId, (int) $row['id'], $to, (int) $row['version'], [
                'cancelled_by' => $actorType === 'customer' ? 'customer' : 'business',
                'reason' => $reason,
                'cancel_code' => $to === 'cancelled' ? $cancelCode : null,
            ]);
            if ($newVersion === null) {
                throw new TransitionException('STALE_APPOINTMENT', 'This appointment was changed by someone else. Reload it and retry.', 409);
            }

            $meta = [];
            if ($override !== null) {
                $meta['timing_override'] = $override;
            }
            if ($cancelCode !== null) {
                $meta['cancel_reason_code'] = $cancelCode;
            }
            $this->appointments->insertEvent(
                $tenantId, (int) $row['id'], 'status.' . $to, $actorType, $actorUserId, $from, $to, $reason,
                $meta !== [] ? $meta : null,
            );
            $this->events->onTransitioned($tenantId, (int) $row['id'], $to);
            $row['status'] = $to;
            $row['version'] = $newVersion;

            return $row;
        });
    }
}
