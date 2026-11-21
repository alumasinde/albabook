<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Core\Database\Database;
use App\Core\Validation\ValidationException;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;

/**
 * Registration checks and renames. Renames keep the old slug in history so it
 * redirects and stays unclaimable (multi-tenancy.md §4).
 */
final class TenantSlugService
{
    public function __construct(
        private readonly SlugPolicy $policy,
        private readonly TenantRepository $tenants,
        private readonly TenantResolver $resolver,
        private readonly Database $db,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ValidationException */
    public function assertAvailable(string $slug, ?int $ignoreTenantId = null): string
    {
        $slug = $this->policy->assertValid($slug);
        if ($this->tenants->slugTaken($slug, $ignoreTenantId)) {
            throw new ValidationException(
                ['slug' => 'That address is already taken. Choose another.'],
                ['slug' => $slug],
            );
        }

        return $slug;
    }

    public function rename(TenantContext $tenant, string $newSlug, ?\App\Core\Http\Request $request = null): TenantContext
    {
        $newSlug = $this->assertAvailable($newSlug, $tenant->tenantId);
        if ($newSlug === $tenant->slug) {
            return $tenant;
        }

        $this->db->transaction(function () use ($tenant, $newSlug): void {
            $this->tenants->recordSlugHistory($tenant->tenantId, $tenant->slug);
            $this->tenants->updateSlug($tenant->tenantId, $newSlug);
        });

        $this->audit->record(new AuditEntry(
            action: AuditAction::TENANT_SLUG_RENAMED,
            tenantId: $tenant->tenantId,
            actorType: 'tenant_user',
            targetType: 'tenant',
            targetId: (string) $tenant->tenantId,
            before: ['slug' => $tenant->slug],
            after: ['slug' => $newSlug],
            request: $request,
        ));

        return new TenantContext(
            tenantId: $tenant->tenantId,
            uuid: $tenant->uuid,
            name: $tenant->name,
            slug: $newSlug,
            timezone: $tenant->timezone,
            currency: $tenant->currency,
            status: $tenant->status,
            actorType: $tenant->actorType,
            readOnly: $tenant->readOnly,
            supportSessionId: $tenant->supportSessionId,
            supportReason: $tenant->supportReason,
            supportExpiresAt: $tenant->supportExpiresAt,
        );
    }

    public function historic(string $slug): ?TenantContext
    {
        return $this->resolver->historic($slug);
    }
}
