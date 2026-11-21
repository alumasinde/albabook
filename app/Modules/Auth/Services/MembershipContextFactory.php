<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Auth\MembershipContext;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantMembershipRepository;

/**
 * Builds the per-request permission set for one (tenant, user) membership.
 * Empty branch rows mean all branches (decisions.md D-007).
 */
final class MembershipContextFactory
{
    public function __construct(
        private readonly TenantMembershipRepository $memberships,
        private readonly RoleRepository $roles,
    ) {}

    public function build(int $userId, int $tenantId): ?MembershipContext
    {
        $membership = $this->memberships->findActive($userId, $tenantId);
        if ($membership === null) {
            return null;
        }

        $tenantUserId = (int) $membership['id'];
        $branchIds = $this->memberships->branchIds($tenantId, $tenantUserId);
        $roleSlugs = array_map(
            static fn(array $role): string => (string) $role['slug'],
            $this->roles->roleSummariesForMembership($tenantId, $tenantUserId),
        );

        return new MembershipContext(
            tenantUserId: $tenantUserId,
            userId: $userId,
            tenantId: $tenantId,
            roleSlugs: array_values($roleSlugs),
            permissions: $this->roles->permissionIdentifiersForMembership($tenantId, $tenantUserId),
            branchIds: $branchIds === [] ? null : array_values($branchIds),
        );
    }
}
