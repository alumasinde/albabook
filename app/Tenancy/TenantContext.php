<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Immutable, trusted tenant identity for one request. Built by TenantResolver
 * from a verified session, slug, domain or support session — never from a
 * browser-supplied tenant id (multi-tenancy.md §1).
 */
final readonly class TenantContext
{
    public function __construct(
        public int $tenantId,
        public string $uuid,
        public string $name,
        public string $slug,
        public string $timezone,
        public string $currency,
        public string $status,
        public string $actorType = 'tenant_user',
        public bool $readOnly = false,
        public ?int $supportSessionId = null,
        public ?string $supportReason = null,
        public ?\DateTimeImmutable $supportExpiresAt = null,
    ) {}

    public function isSupportSession(): bool
    {
        return $this->actorType === 'support_session';
    }
}
