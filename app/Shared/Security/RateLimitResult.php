<?php

declare(strict_types=1);

namespace App\Shared\Security;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {}
}
