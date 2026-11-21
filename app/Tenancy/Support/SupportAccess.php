<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

/**
 * A validated, unexpired support session as seen by the tenant host.
 */
final readonly class SupportAccess
{
    public function __construct(
        public int $sessionId,
        public string $uuid,
        public int $tenantId,
        public string $reason,
        public \DateTimeImmutable $expiresAt,
        public bool $writeAllowed,
    ) {}
}
