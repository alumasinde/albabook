<?php

declare(strict_types=1);

namespace App\Shared\Time;

/**
 * The only source of "now" in application code (booking-engine.md §3).
 * Always returns UTC. Tests substitute FrozenClock.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
