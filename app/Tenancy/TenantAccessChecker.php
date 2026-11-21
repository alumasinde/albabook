<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantAccessDeniedException;
use App\Tenancy\Exceptions\TenantSuspendedException;

/**
 * Central account-level gate. Controllers never branch on tenant status.
 */
final class TenantAccessChecker
{
    private ?TenantPolicyMatrix $matrix = null;

    public function __construct(private readonly TenantPolicyRepository $policies) {}

    public function assertCanAccess(TenantContext $tenant, string $operation): void
    {
        if (in_array($tenant->status, ['suspended', 'archived'], true)) {
            throw new TenantSuspendedException();
        }

        if (!$this->matrix()->allows($tenant->status, $operation)) {
            throw new TenantAccessDeniedException('This operation is not available while the business account is ' . $tenant->status . '.');
        }
    }

    public function allows(TenantContext $tenant, string $operation): bool
    {
        try {
            $this->assertCanAccess($tenant, $operation);

            return true;
        } catch (TenantAccessDeniedException) {
            return false;
        }
    }

    private function matrix(): TenantPolicyMatrix
    {
        return $this->matrix ??= new TenantPolicyMatrix($this->policies->all());
    }
}
