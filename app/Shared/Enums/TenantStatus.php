<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Mirrors the `tenant_statuses` lookup table (decisions.md D-010, D-023).
 * tests/Integration/Database/LookupEnumMirrorTest asserts they agree.
 */
enum TenantStatus: string
{
    case Pending = 'pending';
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    /** Allowed lifecycle transitions (multi-tenancy.md §3). */
    public function canTransitionTo(self $to): bool
    {
        return in_array($to, match ($this) {
            self::Pending => [self::Trial, self::Active, self::Archived],
            self::Trial => [self::Active, self::Suspended, self::Cancelled],
            self::Active => [self::Suspended, self::Cancelled],
            self::Suspended => [self::Active, self::Cancelled],
            self::Cancelled => [self::Active, self::Archived],
            self::Archived => [],
        }, true);
    }
}
