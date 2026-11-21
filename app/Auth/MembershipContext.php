<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * User-level authorization for one membership (multi-tenancy.md §4). Permission
 * checks always go through here; templates only hide controls.
 */
final readonly class MembershipContext
{
    public const MFA_SENSITIVE_PERMISSIONS = [
        'billing.manage',
        'integrations.manage',
        'settings.update',
        'users.manage',
        'roles.manage',
    ];

    /**
     * @param list<string> $permissions
     * @param list<string> $roleSlugs
     * @param list<int>|null $branchIds null means all branches
     */
    public function __construct(
        public int $tenantUserId,
        public int $userId,
        public int $tenantId,
        public array $roleSlugs,
        public array $permissions,
        public ?array $branchIds,
    ) {}

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function holdsAnyMfaSensitivePermission(): bool
    {
        return array_intersect(self::MFA_SENSITIVE_PERMISSIONS, $this->permissions) !== [];
    }

    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->branchIds === null || $branchId === null) {
            return true;
        }

        return in_array($branchId, $this->branchIds, true);
    }
}
