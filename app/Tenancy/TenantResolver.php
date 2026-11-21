<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantNotFoundException;
use App\Tenancy\Support\SupportAccess;

/**
 * Resolves trusted tenant identity by source. It never reads a tenant id from
 * the request body or query string (multi-tenancy.md §2, §7).
 */
final class TenantResolver
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantMembershipRepository $memberships,
    ) {}

    public function forUser(int $userId, int $tenantId): TenantContext
    {
        $tenant = $this->tenants->findById($tenantId);
        if ($tenant === null || $this->memberships->findActive($userId, $tenantId) === null) {
            throw new TenantNotFoundException();
        }

        return $this->context($tenant);
    }

    public function forSlug(string $slug, string $actorType = 'public'): TenantContext
    {
        $tenant = $this->tenants->findBySlug($slug);
        if ($tenant === null) {
            throw new TenantNotFoundException();
        }

        return $this->context($tenant, $actorType);
    }

    public function forDomain(string $normalisedDomain): TenantContext
    {
        $tenant = $this->tenants->findByDomain($normalisedDomain);
        if ($tenant === null) {
            throw new TenantNotFoundException();
        }

        return $this->context($tenant, 'public');
    }

    public function forSupport(SupportAccess $access): TenantContext
    {
        $tenant = $this->tenants->findById($access->tenantId);
        if ($tenant === null) {
            throw new TenantNotFoundException();
        }

        return $this->context(
            $tenant,
            'support_session',
            !$access->writeAllowed,
            $access->sessionId,
            $access->reason,
            $access->expiresAt,
        );
    }

    /** Old slug lookup for 301 redirects (Phase 6 wires the route). */
    public function historic(string $slug): ?TenantContext
    {
        $tenant = $this->tenants->findHistoricTenant($slug);

        return $tenant === null ? null : $this->context($tenant, 'public');
    }

    /** @param array<string, mixed> $tenant */
    private function context(
        array $tenant,
        string $actorType = 'tenant_user',
        bool $readOnly = false,
        ?int $supportSessionId = null,
        ?string $supportReason = null,
        ?\DateTimeImmutable $supportExpiresAt = null,
    ): TenantContext {
        return new TenantContext(
            tenantId: (int) $tenant['id'],
            uuid: (string) $tenant['uuid'],
            name: (string) $tenant['name'],
            slug: (string) $tenant['slug'],
            timezone: (string) $tenant['timezone'],
            currency: (string) $tenant['currency'],
            status: (string) $tenant['status'],
            actorType: $actorType,
            readOnly: $readOnly,
            supportSessionId: $supportSessionId,
            supportReason: $supportReason,
            supportExpiresAt: $supportExpiresAt,
        );
    }
}
