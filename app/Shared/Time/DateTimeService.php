<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * All conversions between UTC instants and local wall-clock time
 * (booking-engine.md §3). Modules never call date(), strtotime() or
 * new DateTime() with a local zone directly.
 *
 * Rules:
 * - Instants are UTC; the DB stores them as 'Y-m-d H:i:s' UTC.
 * - Schedules are local wall-clock times in a branch's IANA timezone.
 * - A local time that does not exist (DST spring-forward gap) yields null;
 *   callers skip it.
 * - An ambiguous local time (DST fall-back overlap) resolves to its FIRST
 *   occurrence.
 */
final class DateTimeService
{
    public const DB_FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly Clock $clock) {}

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    public static function utc(): \DateTimeZone
    {
        return new \DateTimeZone('UTC');
    }

    public static function zone(string $timezone): \DateTimeZone
    {
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException("Unknown IANA timezone \"{$timezone}\".");
        }

        return new \DateTimeZone($timezone);
    }

    /** Parse a UTC value as stored in the database. */
    public function fromDb(string $value): \DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $value, self::utc());
        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid database datetime.');
        }

        return $parsed;
    }

    public function toDb(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::utc())->format(self::DB_FORMAT);
    }

    public function toLocal(\DateTimeImmutable $instant, string $timezone): \DateTimeImmutable
    {
        return $instant->setTimezone(self::zone($timezone));
    }

    /**
     * Convert a local date + wall-clock time to a UTC instant.
     *
     * @param string $date 'Y-m-d'
     * @param string $time 'H:i' or 'H:i:s'
     * @return \DateTimeImmutable|null null when the local time does not exist (DST gap)
     */
    public function localToUtc(string $date, string $time, string $timezone): ?\DateTimeImmutable
    {
        $zone = self::zone($timezone);
        $time = strlen($time) === 5 ? $time . ':00' : $time;
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$date} {$time}", $zone);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($local === false || ($errors !== false && $errors['warning_count'] > 0)) {
            throw new \InvalidArgumentException('Invalid local date or time.');
        }
        if ($local->format('Y-m-d H:i:s') !== "{$date} {$time}") {
            // PHP silently shifts non-existent times forward; a changed wall-clock
            // value means the requested local time was inside a DST gap.
            return null;
        }

        return $this->firstOccurrence($local, $zone)->setTimezone(self::utc());
    }

    /**
     * The UTC range covering one local calendar day: [local 00:00, next local 00:00).
     * Used for "today", day reports and availability (dashboard.md §2).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function localDayToUtcRange(string $date, string $timezone): array
    {
        $zone = self::zone($timezone);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($start === false || $start->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid local date.');
        }
        $end = $start->modify('+1 day');

        return [$start->setTimezone(self::utc()), $end->setTimezone(self::utc())];
    }

    /** Today's local date ('Y-m-d') in a timezone. */
    public function localToday(string $timezone): string
    {
        return $this->clock->now()->setTimezone(self::zone($timezone))->format('Y-m-d');
    }

    /** ISO-8601 weekday (1 = Monday … 7 = Sunday) of a local date. */
    public static function isoWeekday(string $date): int
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, self::utc());
        if ($parsed === false) {
            throw new \InvalidArgumentException('Invalid date.');
        }

        return (int) $parsed->format('N');
    }

    /**
     * If the same wall-clock time also occurs one hour earlier in UTC terms
     * (fall-back overlap), return the earlier instant.
     */
    private function firstOccurrence(\DateTimeImmutable $local, \DateTimeZone $zone): \DateTimeImmutable
    {
        $wallClock = $local->format('Y-m-d H:i:s');
        $earlier = $local->setTimezone(self::utc())->modify('-1 hour')->setTimezone($zone);

        return $earlier->format('Y-m-d H:i:s') === $wallClock ? $earlier : $local;
    }
}
