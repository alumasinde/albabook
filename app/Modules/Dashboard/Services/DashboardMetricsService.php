<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Dashboard\Repositories\DashboardRepository;
use App\Shared\Money\CurrencyFormatter;
use App\Shared\Money\Money;
use App\Shared\Time\DateTimeService;

/**
 * One definition per metric, computed in the tenant timezone and reused by the
 * overview, reports and API (dashboard.md §2 "Metric definitions").
 */
final class DashboardMetricsService
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly DateTimeService $dates,
        private readonly CurrencyFormatter $currency,
    ) {}

    /**
     * @param array<int>|null $branchIds membership branch scope
     * @return array<string, mixed>
     */
    public function summary(int $tenantId, string $timezone, string $currency, ?array $branchIds, bool $financial, bool $canApprove = false): array
    {
        $today = $this->dates->localToday($timezone);
        [$todayFrom, $todayTo] = $this->dates->localDayToUtcRange($today, $timezone);
        [$yesterdayFrom] = $this->dates->localDayToUtcRange($today, $timezone);
        $yesterdayFrom = $yesterdayFrom->modify('-1 day');
        $now = $this->dates->now();

        $monthStart = $this->dates->toLocal($now, $timezone)->modify('first day of this month')->setTime(0, 0);
        $monthFrom = $monthStart->setTimezone(DateTimeService::utc());
        $prevMonthFrom = $monthStart->modify('-1 month')->setTimezone(DateTimeService::utc());
        $nextMonthFrom = $monthStart->modify('+1 month')->setTimezone(DateTimeService::utc());

        $counts = $this->dashboard->appointmentCounts($tenantId, $branchIds, $this->dates->toDb($todayFrom), $this->dates->toDb($todayTo));
        $todayCount = 0;
        foreach ($counts as $status => $n) {
            if (!in_array($status, ['cancelled', 'rejected', 'expired'], true)) {
                $todayCount += $n;
            }
        }
        $confirmedToday = $counts['confirmed'] ?? 0;

        $prevCounts = $this->dashboard->appointmentCounts($tenantId, $branchIds, $this->dates->toDb($yesterdayFrom), $this->dates->toDb($todayFrom));
        $prevToday = 0;
        foreach ($prevCounts as $status => $n) {
            if (!in_array($status, ['cancelled', 'rejected', 'expired'], true)) {
                $prevToday += $n;
            }
        }

        $bookingsThisMonth = array_sum($this->dashboard->appointmentCounts($tenantId, $branchIds, $this->dates->toDb($monthFrom), $this->dates->toDb($nextMonthFrom)));
        $bookingsPrevMonth = array_sum($this->dashboard->appointmentCounts($tenantId, $branchIds, $this->dates->toDb($prevMonthFrom), $this->dates->toDb($monthFrom)));

        $cards = [
            ['key' => 'appointments_today', 'label' => "Today's Appointments", 'value' => (string) $todayCount, 'icon' => 'fa-calendar-day',
             'trend' => $this->trend($todayCount, $prevToday), 'direction' => $this->direction($todayCount, $prevToday)],
            ['key' => 'customers_total', 'label' => 'Total Customers', 'value' => number_format($this->dashboard->activeCustomers($tenantId)), 'icon' => 'fa-user-group', 'hint' => 'Active records'],
        ];
        if ($financial) {
            $collected = $this->dashboard->collectedMinor($tenantId, $branchIds, $this->dates->toDb($monthFrom), $this->dates->toDb($nextMonthFrom));
            $collectedPrev = $this->dashboard->collectedMinor($tenantId, $branchIds, $this->dates->toDb($prevMonthFrom), $this->dates->toDb($monthFrom));
            $cards[] = ['key' => 'revenue_month', 'label' => 'This Month Revenue', 'value' => $this->currency->format(new Money($collected, $currency)), 'icon' => 'fa-chart-line',
                'trend' => $this->trend($collected, $collectedPrev), 'direction' => $this->direction($collected, $collectedPrev)];
        }
        $cards[] = ['key' => 'bookings_month', 'label' => 'Bookings This Month', 'value' => number_format($bookingsThisMonth), 'icon' => 'fa-calendar-check',
            'trend' => $this->trend($bookingsThisMonth, $bookingsPrevMonth), 'direction' => $this->direction($bookingsThisMonth, $bookingsPrevMonth)];

        $schedule = array_map(
            fn(array $row) => [
                'uuid' => $row['uuid'],
                'customer' => $row['customer_name_snapshot'],
                'service' => $row['service_name'] ?? '',
                'staff' => $row['staff_name'],
                'status' => $row['status'],
                'time_label' => $this->dates->toLocal($this->dates->fromDb((string) $row['start_at']), $row['branch_timezone'] ?: $timezone)->format('g:i A'),
            ],
            $this->dashboard->todaySchedule($tenantId, $branchIds, $this->dates->toDb($todayFrom), $this->dates->toDb($todayTo), 8),
        );

        return [
            'greeting' => $this->greeting($timezone),
            'cards' => $cards,
            'today_schedule' => $schedule,
            'chart' => $this->chart($tenantId, $branchIds, $timezone),
            'alerts' => $this->alerts($tenantId, $branchIds, $counts, $canApprove),
            // Only people who may confirm see the queue (appointments.confirm).
            'approvals' => $canApprove ? $this->approvals($tenantId, $branchIds, $timezone) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function approvals(int $tenantId, ?array $branchIds, string $timezone): array
    {
        $tz = new \DateTimeZone($timezone);
        $utc = new \DateTimeZone('UTC');

        return array_map(static function (array $row) use ($tz, $utc): array {
            $start = (new \DateTimeImmutable((string) $row['start_at'], $utc))->setTimezone($tz);
            $asked = (new \DateTimeImmutable((string) $row['created_at'], $utc))->setTimezone($tz);

            return [
                'uuid' => (string) $row['uuid'],
                'reference' => (string) $row['reference'],
                'version' => (int) $row['version'],
                'customer' => (string) $row['customer_name'],
                'phone' => (string) ($row['customer_phone'] ?? ''),
                'service' => (string) ($row['service_name'] ?? ''),
                'staff' => (string) ($row['staff_name'] ?? ''),
                'when_label' => $start->format('D j M, H:i'),
                'requested_label' => $asked->format('j M, H:i'),
            ];
        }, $this->dashboard->pendingQueue($tenantId, $branchIds, $this->dates->toDb($this->dates->now())));
    }

    private function greeting(string $timezone): string
    {
        $hour = (int) $this->dates->now()->setTimezone(DateTimeService::zone($timezone))->format('G');

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
    }

    /**
     * Weekly booking counts for the current month (chart series).
     * @param array<int>|null $branchIds
     * @return array{labels: list<string>, series: list<int>}
     */
    private function chart(int $tenantId, ?array $branchIds, string $timezone): array
    {
        $now = $this->dates->toLocal($this->dates->now(), $timezone);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);
        $labels = [];
        $series = [];
        for ($w = 0; $w < 4; $w += 1) {
            $from = $monthStart->modify('+' . ($w * 7) . ' days');
            $to = $monthStart->modify('+' . (($w + 1) * 7) . ' days');
            $counts = $this->dashboard->appointmentCounts($tenantId, $branchIds, $this->dates->toDb($from->setTimezone(DateTimeService::utc())), $this->dates->toDb($to->setTimezone(DateTimeService::utc())));
            $labels[] = 'Week ' . ($w + 1);
            $series[] = array_sum($counts);
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * @param array<int>|null $branchIds
     * @param array<string, int> $counts
     * @return list<array<string, string>>
     */
    private function alerts(int $tenantId, ?array $branchIds, array $counts, bool $hasQueue = false): array
    {
        $pending = $this->dashboard->pendingBookings($tenantId, $branchIds, $this->dates->toDb($this->dates->now()));
        $alerts = [];
        if ($pending > 0 && !$hasQueue) { // approvers see the queue instead
            $alerts[] = ['tone' => 'warning', 'text' => $pending . ' appointment' . ($pending === 1 ? ' is' : 's are') . ' awaiting confirmation.'];
        }

        return $alerts;
    }

    private function trend(int|float $current, int|float $previous): string
    {
        if ($previous <= 0) {
            return $current > 0 ? '+100%' : '0%';
        }
        $delta = (int) round((($current - $previous) / $previous) * 100);

        return ($delta >= 0 ? '+' : '') . $delta . '%';
    }

    private function direction(int|float $current, int|float $previous): string
    {
        return $current > $previous ? 'up' : ($current < $previous ? 'down' : 'flat');
    }
}
