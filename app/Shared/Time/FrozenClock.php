<?php

declare(strict_types=1);

namespace App\Shared\Time;

/** Test clock: time stands still until moved explicitly. */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->set($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(\DateTimeImmutable|string $now): void
    {
        $value = is_string($now) ? new \DateTimeImmutable($now, new \DateTimeZone('UTC')) : $now;
        $this->now = $value->setTimezone(new \DateTimeZone('UTC'));
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }
}
