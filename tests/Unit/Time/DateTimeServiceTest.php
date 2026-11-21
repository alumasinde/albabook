<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Shared\Time\DateTimeService;
use App\Shared\Time\FrozenClock;
use PHPUnit\Framework\TestCase;

final class DateTimeServiceTest extends TestCase
{
    private DateTimeService $time;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-11-21 06:30:00');
        $this->time = new DateTimeService($this->clock);
    }

    public function testNairobiLocalTimeConvertsToUtc(): void
    {
        $utc = $this->time->localToUtc('2026-11-23', '09:00', 'Africa/Nairobi');

        self::assertSame('2026-11-23 06:00:00', $utc?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $utc?->getTimezone()->getName());
    }

    public function testLocalDayRangeIsComputedInTheBranchTimezone(): void
    {
        [$start, $end] = $this->time->localDayToUtcRange('2026-11-21', 'Africa/Nairobi');

        self::assertSame('2026-11-20 21:00:00', $this->time->toDb($start));
        self::assertSame('2026-11-21 21:00:00', $this->time->toDb($end));
    }

    public function testNonExistentLocalTimeInDstGapReturnsNull(): void
    {
        // Europe/London springs forward 01:00 → 02:00 on 2026-03-29.
        self::assertNull($this->time->localToUtc('2026-03-29', '01:30', 'Europe/London'));
    }

    public function testAmbiguousLocalTimeResolvesToFirstOccurrence(): void
    {
        // Europe/London falls back 02:00 → 01:00 on 2026-10-25; 01:30 happens twice.
        $utc = $this->time->localToUtc('2026-10-25', '01:30', 'Europe/London');

        self::assertSame('2026-10-25 00:30:00', $utc?->format('Y-m-d H:i:s'));
    }

    public function testDstDayIsTwentyThreeHoursLong(): void
    {
        [$start, $end] = $this->time->localDayToUtcRange('2026-03-29', 'Europe/London');

        self::assertSame(23 * 3600, $end->getTimestamp() - $start->getTimestamp());
    }

    public function testLocalTodayUsesTimezoneNotServerDate(): void
    {
        $this->clock->set('2026-11-21 22:30:00'); // already the 22nd in Nairobi (UTC+3)

        self::assertSame('2026-11-22', $this->time->localToday('Africa/Nairobi'));
        self::assertSame('2026-11-21', $this->time->localToday('UTC'));
    }

    public function testDbRoundTripStaysUtc(): void
    {
        $value = '2026-11-21 06:30:00';

        self::assertSame($value, $this->time->toDb($this->time->fromDb($value)));
    }

    public function testUnknownTimezoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->time->localToUtc('2026-11-21', '09:00', 'Nairobi');
    }

    public function testInvalidTimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->time->localToUtc('2026-11-21', '25:00', 'Africa/Nairobi');
    }

    public function testIsoWeekday(): void
    {
        self::assertSame(6, DateTimeService::isoWeekday('2026-11-21')); // Saturday
        self::assertSame(7, DateTimeService::isoWeekday('2026-11-22'));
    }

    public function testFrozenClockAdvances(): void
    {
        $this->clock->advance('+5 minutes');

        self::assertSame('2026-11-21 06:35:00', $this->clock->now()->format('Y-m-d H:i:s'));
    }
}
