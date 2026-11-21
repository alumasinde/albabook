<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Ramsey\Uuid\Uuid;

/**
 * UUIDs for public identifiers (plan.md §13). Version 7 is time-ordered, so
 * CHAR(36) uuid columns stay index-friendly as tables grow.
 */
final class UuidFactory
{
    public function generate(): string
    {
        return Uuid::uuid7()->toString();
    }

    public static function isValid(string $value): bool
    {
        return Uuid::isValid($value);
    }
}
