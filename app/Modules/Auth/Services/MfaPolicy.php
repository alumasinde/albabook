<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Auth\MembershipContext;
use App\Tenancy\TenantSettingsService;

/**
 * Tenant-level MFA requirement (auth.md §8c). A tenant can require MFA for any
 * membership holding a privileged permission; individual users may also enrol
 * voluntarily, which is tracked by the presence of a confirmed factor.
 */
final class MfaPolicy
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function isRequiredForMembership(int $tenantId, MembershipContext $membership): bool
    {
        return $this->settings->mfaRequiredForPrivilegedRoles($tenantId)
            && $membership->holdsAnyMfaSensitivePermission();
    }
}
