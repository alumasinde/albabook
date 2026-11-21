<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * Request-scoped holder for the tenant context. Services should normally
 * receive the TenantContext explicitly; this exists for the HTTP/UI layer.
 */
final class CurrentTenant
{
    public ?TenantContext $context = null;

    public function set(?TenantContext $context): void
    {
        $this->context = $context;
    }

    public function get(): ?TenantContext
    {
        return $this->context;
    }

    public function require(): TenantContext
    {
        return $this->context ?? throw new \LogicException('No tenant in context for this request.');
    }
}
