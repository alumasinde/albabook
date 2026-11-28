<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Auth\Context\MembershipContext;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Time\Clock;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMembershipRepository;

/**
 * Team member lifecycle after an invitation is accepted: change roles,
 * limit branches, deactivate (immediately signs the member out of this
 * workspace) and reactivate.
 *
 * Guardrails, same spirit as invitations:
 *  - nobody edits their own membership (no self-lockout or self-promotion);
 *  - only an owner can touch an owner or grant the owner role;
 *  - non-owners cannot grant permissions they do not hold, and must see
 *    every branch themselves (a branch-limited manager cannot widen scope);
 *  - the business always keeps at least one active owner.
 */
final class TeamMemberService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly TenantMembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One member with roles and branch scope, for the edit page.
     *
     * @return array<string, mixed>|null
     */
    public function member(int $tenantId, int $tenantUserId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT tu.id AS tenant_user_id, tu.user_id, tu.status, tu.joined_at,
                    u.first_name, u.last_name, u.email
               FROM tenant_users tu
               JOIN users u ON u.id = tu.user_id
              WHERE tu.tenant_id = :t AND tu.id = :m AND tu.status IN ('active', 'suspended')
              LIMIT 1",
        );
        $stmt->execute(['t' => $tenantId, 'm' => $tenantUserId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $roleIds = $this->roleIds($tenantId, $tenantUserId);
        $row['role_ids'] = $roleIds;
        $row['role_slugs'] = array_column($this->roles->roleSummariesForMembership($tenantId, $tenantUserId), 'slug');
        $row['branch_ids'] = $this->memberships->branchIds($tenantId, $tenantUserId);

        return $row;
    }

    /** @return list<array{id: int, name: string}> */
    public function branches(int $tenantId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, name FROM branches WHERE tenant_id = :t AND is_active = 1 ORDER BY name',
        );
        $stmt->execute(['t' => $tenantId]);

        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $stmt->fetchAll());
    }

    /**
     * Replace roles and branch scope. Empty branch list = every branch.
     *
     * @param array<string, mixed> $input role_ids[], branch_scope ('all'|'some'), branch_ids[]
     * @throws ValidationException
     */
    public function update(TenantContext $tenant, MembershipContext $actor, int $tenantUserId, array $input, Request $request): void
    {
        $tenantId = $tenant->tenantId;
        $member = $this->guardTarget($tenantId, $actor, $tenantUserId);

        $roleIds = $this->validRoleIds($tenantId, $actor, $input);
        $branchIds = $this->validBranchIds($tenantId, $input);

        $isOwnerNow = in_array('owner', $member['role_slugs'], true);
        $staysOwner = in_array($this->ownerRoleId($tenantId), $roleIds, true);
        if ($isOwnerNow && !$staysOwner && $member['status'] === 'active' && $this->activeOwnerCount($tenantId) <= 1) {
            throw new ValidationException(['role_ids' => 'The business needs at least one owner. Make someone else an owner first.']);
        }

        $before = ['role_ids' => $member['role_ids'], 'branch_ids' => $member['branch_ids']];
        $this->db->transaction(function () use ($tenantId, $tenantUserId, $roleIds, $branchIds): void {
            $pdo = $this->db->pdo();
            $pdo->prepare('DELETE FROM membership_roles WHERE tenant_id = :t AND tenant_user_id = :m')
                ->execute(['t' => $tenantId, 'm' => $tenantUserId]);
            foreach ($roleIds as $roleId) {
                $this->roles->assignRole($tenantId, $tenantUserId, $roleId);
            }
            $pdo->prepare('DELETE FROM membership_branches WHERE tenant_id = :t AND tenant_user_id = :m')
                ->execute(['t' => $tenantId, 'm' => $tenantUserId]);
            $insert = $pdo->prepare('INSERT INTO membership_branches (tenant_id, tenant_user_id, branch_id) VALUES (:t, :m, :b)');
            foreach ($branchIds as $branchId) {
                $insert->execute(['t' => $tenantId, 'm' => $tenantUserId, 'b' => $branchId]);
            }
        });

        $this->audit->record(new AuditEntry(
            action: AuditAction::MEMBER_UPDATED,
            tenantId: $tenantId,
            actorType: 'tenant_user',
            actorId: $actor->userId,
            targetType: 'tenant_user',
            targetId: (string) $tenantUserId,
            before: $before,
            after: ['role_ids' => $roleIds, 'branch_ids' => $branchIds],
            request: $request,
        ));
    }

    /**
     * Suspend the membership and end the member's sessions in this
     * workspace. Their staff profile (if any) is untouched — deactivate it
     * under Staff if they also stop taking bookings.
     *
     * @throws ValidationException
     */
    public function deactivate(TenantContext $tenant, MembershipContext $actor, int $tenantUserId, Request $request): void
    {
        $tenantId = $tenant->tenantId;
        $member = $this->guardTarget($tenantId, $actor, $tenantUserId);
        if ($member['status'] !== 'active') {
            return;
        }
        if (in_array('owner', $member['role_slugs'], true) && $this->activeOwnerCount($tenantId) <= 1) {
            throw new ValidationException(['member' => 'You cannot deactivate the only owner.']);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->transaction(function () use ($tenantId, $tenantUserId, $member, $now): void {
            $this->db->pdo()->prepare(
                "UPDATE tenant_users SET status = 'suspended' WHERE tenant_id = :t AND id = :m AND status = 'active'",
            )->execute(['t' => $tenantId, 'm' => $tenantUserId]);
            // Membership is re-checked on every request, so access ends now;
            // revoking the sessions parked in this workspace also signs the
            // open tabs out instead of showing errors.
            $this->db->pdo()->prepare(
                'UPDATE user_sessions SET revoked_at = :at
                  WHERE user_id = :u AND active_tenant_id = :t AND revoked_at IS NULL',
            )->execute(['at' => $now, 'u' => (int) $member['user_id'], 't' => $tenantId]);
        });

        $this->audit->record(new AuditEntry(
            action: AuditAction::MEMBER_DEACTIVATED,
            tenantId: $tenantId,
            actorType: 'tenant_user',
            actorId: $actor->userId,
            targetType: 'tenant_user',
            targetId: (string) $tenantUserId,
            request: $request,
        ));
    }

    /** @throws ValidationException */
    public function reactivate(TenantContext $tenant, MembershipContext $actor, int $tenantUserId, Request $request): void
    {
        $tenantId = $tenant->tenantId;
        $member = $this->guardTarget($tenantId, $actor, $tenantUserId);
        if ($member['status'] !== 'suspended') {
            return;
        }
        $this->db->pdo()->prepare(
            "UPDATE tenant_users SET status = 'active' WHERE tenant_id = :t AND id = :m AND status = 'suspended'",
        )->execute(['t' => $tenantId, 'm' => $tenantUserId]);

        $this->audit->record(new AuditEntry(
            action: AuditAction::MEMBER_REACTIVATED,
            tenantId: $tenantId,
            actorType: 'tenant_user',
            actorId: $actor->userId,
            targetType: 'tenant_user',
            targetId: (string) $tenantUserId,
            request: $request,
        ));
    }

    /**
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function guardTarget(int $tenantId, MembershipContext $actor, int $tenantUserId): array
    {
        $member = $this->member($tenantId, $tenantUserId);
        if ($member === null) {
            throw new ValidationException(['member' => 'That team member was not found.']);
        }
        if ($tenantUserId === $actor->tenantUserId) {
            throw new ValidationException(['member' => 'You cannot change your own access. Ask another owner or admin.']);
        }
        $actorIsOwner = in_array('owner', $actor->roleSlugs, true);
        if (!$actorIsOwner) {
            if (in_array('owner', $member['role_slugs'], true)) {
                throw new ValidationException(['member' => 'Only an owner can change another owner.']);
            }
            if ($actor->branchIds !== null) {
                throw new ValidationException(['member' => 'Only team members with access to every branch can manage the team.']);
            }
            // A member holding permissions the actor lacks is out of reach:
            // otherwise a manager could deactivate (or demote) an admin.
            $held = $this->roles->permissionIdentifiersForMembership($tenantId, $tenantUserId);
            if (array_diff($held, $actor->permissions) !== []) {
                throw new ValidationException(['member' => 'This person has more access than you, so you cannot change it.']);
            }
        }

        return $member;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int>
     * @throws ValidationException
     */
    private function validRoleIds(int $tenantId, MembershipContext $actor, array $input): array
    {
        $requested = is_array($input['role_ids'] ?? null) ? $input['role_ids'] : [];
        $requested = array_values(array_unique(array_map('intval', $requested)));
        if ($requested === []) {
            throw new ValidationException(['role_ids' => 'Choose at least one role.']);
        }
        $available = [];
        foreach ($this->roles->forTenant($tenantId) as $role) {
            $available[(int) $role['id']] = (string) $role['slug'];
        }
        if (array_diff($requested, array_keys($available)) !== []) {
            throw new ValidationException(['role_ids' => 'Choose roles from this business.']);
        }
        $isOwner = in_array('owner', $actor->roleSlugs, true);
        $grantsOwner = in_array('owner', array_values(array_intersect_key($available, array_flip($requested))), true);
        if ($grantsOwner && !$isOwner) {
            throw new ValidationException(['role_ids' => 'Only the owner can grant the owner role.']);
        }
        if (!$isOwner) {
            $granted = $this->roles->permissionIdentifiersForRoles($tenantId, $requested);
            if (array_diff($granted, $actor->permissions) !== []) {
                throw new ValidationException(['role_ids' => 'You cannot grant a role with permissions you do not have yourself.']);
            }
        }

        return $requested;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int> empty = all branches
     * @throws ValidationException
     */
    private function validBranchIds(int $tenantId, array $input): array
    {
        if (($input['branch_scope'] ?? 'all') !== 'some') {
            return [];
        }
        $requested = is_array($input['branch_ids'] ?? null) ? $input['branch_ids'] : [];
        $requested = array_values(array_unique(array_map('intval', $requested)));
        if ($requested === []) {
            throw new ValidationException(['branch_ids' => 'Choose at least one branch, or give access to every branch.']);
        }
        $valid = array_column($this->branches($tenantId), 'id');
        if (array_diff($requested, $valid) !== []) {
            throw new ValidationException(['branch_ids' => 'Choose active branches of this business.']);
        }

        return $requested;
    }

    /** @return list<int> */
    private function roleIds(int $tenantId, int $tenantUserId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT role_id FROM membership_roles WHERE tenant_id = :t AND tenant_user_id = :m',
        );
        $stmt->execute(['t' => $tenantId, 'm' => $tenantUserId]);

        return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function ownerRoleId(int $tenantId): int
    {
        return (int) ($this->roles->findForTenantBySlug($tenantId, 'owner')['id'] ?? 0);
    }

    private function activeOwnerCount(int $tenantId): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(DISTINCT tu.id)
               FROM tenant_users tu
               JOIN membership_roles mr ON mr.tenant_id = tu.tenant_id AND mr.tenant_user_id = tu.id
               JOIN roles r ON r.id = mr.role_id AND r.tenant_id = tu.tenant_id AND r.slug = 'owner'
              WHERE tu.tenant_id = :t AND tu.status = 'active'",
        );
        $stmt->execute(['t' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }
}
