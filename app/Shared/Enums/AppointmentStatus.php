<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Mirrors the `appointment_statuses` lookup table (booking-engine.md §10.1,
 * decisions.md D-023). tests/Integration/Database/LookupEnumMirrorTest asserts
 * the cases, blocksTime() and isTerminal() match the seeded rows.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /** Statuses whose occupancy blocks staff and resources. */
    public function blocksTime(): bool
    {
        return in_array($this, self::timeBlocking(), true);
    }

    public function isTerminal(): bool
    {
        return !$this->blocksTime();
    }

    /**
     * Literal list for occupancy queries (`status IN (...)`), so the hot path
     * needs no join on the lookup table.
     *
     * @return list<self>
     */
    public static function timeBlocking(): array
    {
        return [self::Pending, self::Confirmed, self::CheckedIn];
    }

    /** @return list<string> */
    public static function timeBlockingValues(): array
    {
        return array_map(static fn(self $s): string => $s->value, self::timeBlocking());
    }
}
