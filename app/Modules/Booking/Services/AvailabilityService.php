<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Repositories\BookingPageRepository;
use App\Shared\Time\DateTimeService;

/**
 * Public availability for the booking page (booking-engine.md §5): the
 * intersect of business hours and staff shifts, minus breaks and occupied
 * ranges, cut into policy slots. Holds arrive with the Phase 7 lock
 * protocol; the write path re-checks occupancy inside a transaction.
 */
final class AvailabilityService
{
    private const DEFAULT_SLOT_INTERVAL = 30;
    private const DEFAULT_MIN_NOTICE = 30;

    public function __construct(
        private readonly BookingPageRepository $pages,
        private readonly DateTimeService $dates,
    ) {}

    /**
     * Available start times for a service on a local date.
     * When $staffId is null every bookable candidate contributes; duplicate
     * times collapse to the earliest-priority staff member.
     *
     * @param array<string, mixed> $branch  booking-page branch row
     * @param array<string, mixed> $service services row (id, duration_minutes, buffers)
     * @return list<array{start_time: string, end_time: string, staff_id: int, staff_name: string}>
     */
    public function slots(
        int $tenantId,
        array $branch,
        array $service,
        ?int $staffId,
        string $date,
        string $timezone,
    ): array {
        $branchTz = $branch['timezone'] ?: $timezone;
        $localDay = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone($branchTz));
        if ($localDay === false) {
            return [];
        }
        $weekday = (int) $localDay->format('N');

        $staff = $this->pages->staffForService($tenantId, (int) $service['id'], (int) $branch['id']);
        if ($staffId !== null) {
            $staff = array_values(array_filter($staff, static fn($s): bool => (int) $s['id'] === $staffId));
        }
        if ($staff === []) {
            return [];
        }

        $policy = $this->pages->policy($tenantId, (int) $branch['id']);
        $interval = (int) ($policy['slot_interval_minutes'] ?? self::DEFAULT_SLOT_INTERVAL) ?: self::DEFAULT_SLOT_INTERVAL;
        $minNotice = (int) ($policy['min_notice_minutes'] ?? self::DEFAULT_MIN_NOTICE);
        $maxAdvance = $policy['max_advance_days'] ?? null;
        if ($maxAdvance !== null) {
            $latest = $this->dates->now()
                ->setTimezone(new \DateTimeZone($branchTz))
                ->modify('+' . (int) $maxAdvance . ' days');
            if ($date > $latest->format('Y-m-d')) {
                return [];
            }
        }

        $earliest = $this->dates->now()->modify("+{$minNotice} minutes");
        [$dayStart, $dayEnd] = $this->dates->localDayToUtcRange($date, $branchTz);

        $branchOverride = $this->pages->branchOverride($tenantId, (int) $branch['id'], $date);
        if ($branchOverride !== null && (int) $branchOverride['is_closed'] === 1) {
            return [];
        }

        $business = $branchOverride !== null && $branchOverride['opens_at'] !== null && $branchOverride['closes_at'] !== null
            ? [['opens_at' => $branchOverride['opens_at'], 'closes_at' => $branchOverride['closes_at']]]
            : $this->pages->businessHours($tenantId, (int) $branch['id'], $weekday, $date);
        $staffIds = array_map(static fn($s): int => (int) $s['id'], $staff);
        $schedules = $this->pages->staffSchedules($tenantId, $staffIds, (int) $branch['id'], $weekday, $date);
        $breaks = $this->pages->staffBreaks($tenantId, $staffIds, $weekday, $date);
        $occupied = $this->pages->occupiedRanges($tenantId, $staffIds, $this->dates->toDb($dayStart), $this->dates->toDb($dayEnd));
        $absences = $this->pages->staffAbsences($tenantId, $staffIds, $this->dates->toDb($dayStart), $this->dates->toDb($dayEnd));
        $branchBlocked = $this->pages->branchBlockedRanges($tenantId, (int) $branch['id'], $this->dates->toDb($dayStart), $this->dates->toDb($dayEnd));

        $duration = (int) $service['duration_minutes'];
        $byStaff = [];
        foreach ($schedules as $row) {
            $byStaff[(int) $row['staff_id']][] = [(int) $this->minutes($row['starts_at']), (int) $this->minutes($row['ends_at'])];
        }
        foreach ($this->pages->staffOverrides($tenantId, $staffIds, $date) as $override) {
            $sid = (int) $override['staff_id'];
            $byStaff[$sid] = (int) $override['is_closed'] === 1
                ? []
                : [[$this->minutes((string) $override['opens_at']), $this->minutes((string) $override['closes_at'])]];
        }
        $breaksByStaff = [];
        foreach ($breaks as $row) {
            $breaksByStaff[(int) $row['staff_id']][] = [(int) $this->minutes($row['starts_at']), (int) $this->minutes($row['ends_at'])];
        }
        $requirements = $this->pages->serviceRequirements($tenantId, (int) $service['id']);
        $occupiedByStaff = [];
        foreach ($occupied as $row) {
            $occupiedByStaff[(int) $row['staff_id']][] = [
                $this->minutesFromDb($row['occupied_start_at'], $dayStart, $branchTz),
                $this->minutesFromDb($row['occupied_end_at'], $dayStart, $branchTz),
            ];
        }
        foreach ($absences as $row) {
            $occupiedByStaff[(int) $row['staff_id']][] = [
                $this->minutesFromDb($row['starts_at'], $dayStart, $branchTz),
                $this->minutesFromDb($row['ends_at'], $dayStart, $branchTz),
            ];
        }
        foreach ($branchBlocked as $row) {
            $busy = [
                $this->minutesFromDb($row['starts_at'], $dayStart, $branchTz),
                $this->minutesFromDb($row['ends_at'], $dayStart, $branchTz),
            ];
            foreach ($staffIds as $sid) {
                $occupiedByStaff[$sid][] = $busy;
            }
        }

        $businessWindows = array_map(
            fn($row): array => $this->window((int) $this->minutes($row['opens_at']), (int) $this->minutes($row['closes_at'])),
            $business,
        );

        $slots = [];
        $seen = [];
        foreach ($staff as $member) {
            $sid = (int) $member['id'];
            $windows = $this->intersect($byStaff[$sid] ?? [], $businessWindows);
            if ($windows === []) {
                continue;
            }
            $free = $this->subtract($windows, $breaksByStaff[$sid] ?? []);
            $free = $this->subtract($free, $occupiedByStaff[$sid] ?? []);

            foreach ($free as [$wStart, $wEnd]) {
                for ($t = $wStart; $t + $duration <= $wEnd; $t += $interval) {
                    $startUtc = $this->localDateTime($date, $t, $branchTz);
                    $endUtc = $this->localDateTime($date, $t + $duration, $branchTz);
                    if ($endUtc <= $earliest || $startUtc > $dayEnd) {
                        continue;
                    }
                    // start_time/end_time are branch-local wall-clock labels;
                    // the client submits them back for slotAvailableForStaff.
                    $key = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                    if ($staffId === null && isset($seen[$key])) {
                        continue; // earlier-priority staff already covers this time
                    }
                    if ($requirements !== [] && !$this->resourcesFree(
                        $tenantId,
                        (int) $branch['id'],
                        $requirements,
                        $startUtc,
                        $endUtc,
                        $weekday,
                        $date,
                        $t,
                        $duration,
                    )) {
                        $seen[$key] = true; // no resource capacity at this time for any staff
                        continue;
                    }
                    $seen[$key] = true;
                    $endT = $t + $duration;
                    $slots[] = [
                        'start_time' => $key,
                        'end_time' => sprintf('%02d:%02d', intdiv($endT, 60) % 24, $endT % 60),
                        'staff_id' => $sid,
                        'staff_name' => (string) $member['display_name'],
                    ];
                }
            }
        }

        usort($slots, static fn($a, $b): int => strcmp($a['start_time'], $b['start_time']));

        return $slots;
    }

    /**
     * Every resource type the service requires has a free, schedulable
     * resource at this slot. Non-mutating mirror of the write-path check —
     * slot listing cannot know guest_count, so per_guest occupancy is
     * validated as quantity 1 and re-checked for real at booking time.
     *
     * @param list<array{resource_type_id: int, quantity: int, occupancy: string}> $requirements
     */
    private function resourcesFree(
        int $tenantId,
        int $branchId,
        array $requirements,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        int $weekday,
        string $date,
        int $startMinute,
        int $duration,
    ): bool {
        $endMinute = $startMinute + $duration;
        $localStart = sprintf('%02d:%02d:00', intdiv($startMinute, 60) % 24, $startMinute % 60);
        $localEnd = sprintf('%02d:%02d:00', intdiv($endMinute, 60) % 24, $endMinute % 60);
        foreach ($requirements as $req) {
            $qty = (string) $req['occupancy'] === 'per_guest' ? 1 : max(1, (int) $req['quantity']);
            if (!$this->pages->hasFreeResource(
                $tenantId,
                $branchId,
                (int) $req['resource_type_id'],
                $this->dates->toDb($startUtc),
                $this->dates->toDb($endUtc),
                $weekday,
                $localStart,
                $localEnd,
                $date,
                $qty,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-verify that a requested start time is still a legal slot for a
     * specific staff member. Used by the public booking write path before it
     * enters the transaction; the row lock inside ManualBookingService is the
     * final arbiter.
     *
     * @param array<string, mixed> $branch
     * @param array<string, mixed> $service
     */
    public function slotAvailableForStaff(
        int $tenantId,
        array $branch,
        array $service,
        int $staffId,
        string $date,
        string $time,
        string $timezone,
    ): bool {
        $slots = $this->slots($tenantId, $branch, $service, $staffId, $date, $timezone);
        foreach ($slots as $slot) {
            if ($slot['start_time'] === $time) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidate staff for a service at a branch, in tenant priority order.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $tenantId, int $serviceId, int $branchId): array
    {
        return $this->pages->staffForService($tenantId, $serviceId, $branchId);
    }

    /** Minutes after local midnight; normalises "HH:MM[:SS]" and overnight ends. */
    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    /** Convert a UTC db datetime into minutes after local midnight of the range day. */
    private function minutesFromDb(string $dbUtc, \DateTimeImmutable $dayStartUtc, string $branchTz): int
    {
        $local = $this->dates->fromDb($dbUtc)->setTimezone(new \DateTimeZone($branchTz));
        $dayStartLocal = $dayStartUtc->setTimezone(new \DateTimeZone($branchTz));
        $seconds = $local->getTimestamp() - $dayStartLocal->getTimestamp();

        return (int) floor($seconds / 60);
    }

    /**
     * Normalise an overnight window: end <= start means it crosses midnight.
     *
     * @return array{0: int, 1: int}
     */
    private function window(int $start, int $end): array
    {
        return $end <= $start ? [$start, $end + 1440] : [$start, $end];
    }

    /**
     * Intersect two window lists (each [start, end] in local minutes).
     *
     * @param list<array{0: int, 1: int}> $a
     * @param list<array{0: int, 1: int}> $b
     * @return list<array{0: int, 1: int}>
     */
    private function intersect(array $a, array $b): array
    {
        $out = [];
        foreach ($a as [$s1, $e1]) {
            [$s1, $e1] = $this->window($s1, $e1);
            foreach ($b as [$s2, $e2]) {
                $s = max($s1, $s2);
                $e = min($e1, $e2);
                if ($e > $s) {
                    $out[] = [$s, $e];
                }
            }
        }

        return $out;
    }

    /**
     * Subtract busy windows from free windows.
     *
     * @param list<array{0: int, 1: int}> $free
     * @param list<array{0: int, 1: int}> $busy
     * @return list<array{0: int, 1: int}>
     */
    private function subtract(array $free, array $busy): array
    {
        foreach ($busy as [$bs, $be]) {
            $next = [];
            foreach ($free as [$fs, $fe]) {
                if ($be <= $fs || $bs >= $fe) {
                    $next[] = [$fs, $fe];
                    continue;
                }
                if ($bs > $fs) {
                    $next[] = [$fs, min($bs, $fe)];
                }
                if ($be < $fe) {
                    $next[] = [max($be, $fs), $fe];
                }
            }
            $free = $next;
        }

        return $free;
    }

    private function localDateTime(string $date, int $minutes, string $timezone): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone($timezone));
        if ($day === false) {
            throw new \InvalidArgumentException('Invalid local date.');
        }

        return $day->modify('+' . $minutes . ' minutes')->setTimezone(new \DateTimeZone('UTC'));
    }
}
