<?php

declare(strict_types=1);

namespace App\Modules\Auth\Context;

use App\Tenancy\TenantPolicyMatrix;

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
     * @param list<string> $tenantOperations account-level operations the
     *        tenant status allows; ['*'] means unrestricted (default keeps
     *        standalone construction in tests permissive)
     */
    public function __construct(
        public int $tenantUserId,
        public int $userId,
        public int $tenantId,
        public array $roleSlugs,
        public array $permissions,
        public ?array $branchIds,
        public array $tenantOperations = ['*'],
    ) {}

    /**
     * True when the membership holds the permission AND the tenant's
     * account status permits the mapped operation (multi-tenancy.md §3:
     * e.g. a cancelled business cannot create bookings, only read and
     * export).
     */
    public function can(string $permission): bool
    {
        if (!in_array($permission, $this->permissions, true)) {
            return false;
        }

        return in_array('*', $this->tenantOperations, true)
            || in_array(TenantPolicyMatrix::operationForPermission($permission), $this->tenantOperations, true);
    }

    public function holdsAnyMfaSensitivePermission(): bool
    {
        return array_intersect(self::MFA_SENSITIVE_PERMISSIONS, $this->permissions) !== [];
    }

    /** The user id to stamp on FK-backed audit columns; NULL for synthetic
     * support memberships (userId 0 has no users row).
     */
    public function actorUserId(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
    }

    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->branchIds === null || $branchId === null) {
            return true;
        }

        return in_array($branchId, $this->branchIds, true);
    }
}
