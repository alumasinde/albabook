<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Context\MembershipContext;
use App\Core\Config\Config;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Auth\Context\AuthenticatedUser;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Repositories\InvitationRepository;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Security\SecureToken;
use App\Shared\Support\UuidFactory;
use App\Shared\Time\Clock;
use App\Shared\Validation\PhoneNumber;
use App\Tenancy\RoleRepository;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantMembershipRepository;
use App\Tenancy\TenantResolver;

/**
 * Invitations bind one email, one tenant and a fixed role set (auth.md §5).
 * The link proves email ownership, so new users skip verification; existing
 * users must be signed in with that verified email.
 */
final class InvitationService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Database $db,
        private readonly Validator $validator,
        private readonly InvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly TenantMembershipRepository $memberships,
        private readonly RoleRepository $roles,
        private readonly TenantResolver $resolver,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly AuthMailer $mailer,
        private readonly UuidFactory $uuids,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $input email, role_ids[]
     * @throws ValidationException
     */
    public function invite(TenantContext $tenant, MembershipContext $actor, array $input, Request $request): void
    {
        $data = $this->validator->validate($input, [
            'email' => ['required', 'string', 'email'],
        ]);
        $email = strtolower((string) $data['email']);

        $roleIds = $this->requestedRoleIds($tenant->tenantId, $input, $actor);

        $existing = $this->users->findByEmail($email);
        if ($existing !== null && $this->memberships->findActive((int) $existing['id'], $tenant->tenantId) !== null) {
            throw new ValidationException(['email' => 'This person is already a member of this business.']);
        }

        $wasPending = $this->invitations->isPendingForEmail($tenant->tenantId, $email);
        $now = $this->clock->now();
        $token = SecureToken::generate();
        $expiresAt = $now
            ->modify('+' . $this->config->int('auth.tokens.invitation_days', 7) . ' days')
            ->format('Y-m-d H:i:s');

        $this->invitations->revokePendingForEmail($tenant->tenantId, $email, $now->format('Y-m-d H:i:s'));
        $created = $this->invitations->create(
            $tenant->tenantId,
            $email,
            $actor->userId,
            SecureToken::hash($token),
            $expiresAt,
            $roleIds,
        );

        $this->audit->record(new AuditEntry(
            action: $wasPending ? AuditAction::INVITATION_RESENT : AuditAction::INVITATION_SENT,
            tenantId: $tenant->tenantId,
            actorType: 'tenant_user',
            actorId: $actor->userId,
            targetType: 'invitation',
            targetId: (string) $created['id'],
            after: ['email' => $email, 'roles' => $roleIds],
            request: $request,
        ));

        $this->mailer->sendInvitation(
            $email,
            $tenant->name,
            $this->actorName($actor->userId),
            $token,
            new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')),
            $request,
        );
    }

    /**
     * New user accepting an invitation: creates the account, marks the email
     * verified (the link proved ownership) and activates the membership.
     *
     * @param array<string, mixed> $input first_name, middle_name?, last_name, password, password_confirmation
     * @return array{user_id: int, tenant_id: int, email: string}
     * @throws ValidationException|InvalidTokenException
     */
    public function acceptAsNewUser(string $token, array $input, Request $request): array
    {
        $invitation = $this->requireValid($token);
        $tenantId = (int) $invitation['tenant_id'];
        $email = strtolower((string) $invitation['email']);

        if ($this->users->findByEmail($email) !== null) {
            throw new ValidationException([
                'email' => 'An account for this email already exists. Sign in to accept the invitation.',
            ]);
        }

        $data = $this->validator->validate($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'phone'],
            'password' => ['required', 'string', 'max:128', 'confirmed'],
        ]);
        $violations = $this->policy->violations((string) $data['password']);
        if ($violations !== []) {
            throw new ValidationException(['password' => $violations[0]]);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $roleIds = array_map(
            static fn(array $role): int => (int) $role['role_id'],
            $this->invitations->rolesForInvitation($tenantId, (int) $invitation['id']),
        );

        $userId = $this->db->transaction(function () use ($data, $email, $invitation, $tenantId, $roleIds, $now): int {
            $userId = $this->users->create([
                'first_name' => (string) $data['first_name'],
                'middle_name' => $data['middle_name'] === null ? null : (string) $data['middle_name'],
                'last_name' => (string) $data['last_name'],
                'email' => $email,
                'phone_e164' => PhoneNumber::normalise($data['phone'] === null ? null : (string) $data['phone']),
                'password_hash' => $this->hasher->hash((string) $data['password']),
                'locale' => 'en',
            ], $this->uuids->generate());

            $this->users->markEmailVerified($userId, $now);
            $tenantUserId = $this->memberships->create(
                $tenantId,
                $userId,
                'active',
                (int) $invitation['invited_by_user_id'],
            );
            $this->assignRoles($tenantId, $tenantUserId, $roleIds);
            $this->invitations->accept((int) $invitation['id'], $now);

            return $userId;
        });

        $this->audit->record(new AuditEntry(
            action: AuditAction::INVITATION_ACCEPTED,
            tenantId: $tenantId,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'invitation',
            targetId: (string) $invitation['id'],
            request: $request,
        ));

        return ['user_id' => $userId, 'tenant_id' => $tenantId, 'email' => $email];
    }

    /**
     * Existing user accepting: the signed-in verified email must match.
     *
     * @throws ValidationException|InvalidTokenException
     */
    public function acceptAsExistingUser(string $token, AuthenticatedUser $user, Request $request): TenantContext
    {
        $invitation = $this->requireValid($token);
        $tenantId = (int) $invitation['tenant_id'];
        $email = strtolower((string) $invitation['email']);

        if ($email !== strtolower($user->email)) {
            throw new ValidationException(['email' => 'Sign in with the invited email address to accept.']);
        }
        if (!$user->emailVerified) {
            throw new ValidationException(['email' => 'Verify your email address before accepting the invitation.']);
        }

        $roleIds = array_map(
            static fn(array $role): int => (int) $role['role_id'],
            $this->invitations->rolesForInvitation($tenantId, (int) $invitation['id']),
        );
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($user, $invitation, $tenantId, $roleIds, $now): void {
            $membership = $this->memberships->find($user->id, $tenantId);
            if ($membership === null) {
                $tenantUserId = $this->memberships->create(
                    $tenantId,
                    $user->id,
                    'active',
                    (int) $invitation['invited_by_user_id'],
                );
            } else {
                if (in_array((string) $membership['status'], ['suspended', 'removed'], true)) {
                    throw new ValidationException(['email' => 'Your membership in this business is not active.']);
                }
                $tenantUserId = (int) $membership['id'];
                if ($membership['status'] !== 'active') {
                    $this->memberships->activate($tenantUserId);
                }
            }

            $this->assignRoles($tenantId, $tenantUserId, $roleIds);
            $this->invitations->accept((int) $invitation['id'], $now);
        });

        $this->audit->record(new AuditEntry(
            action: AuditAction::INVITATION_ACCEPTED,
            tenantId: $tenantId,
            actorType: 'tenant_user',
            actorId: $user->id,
            targetType: 'invitation',
            targetId: (string) $invitation['id'],
            request: $request,
        ));

        return $this->resolver->forUser($user->id, $tenantId);
    }

    public function revoke(TenantContext $tenant, int $invitationId, int $actorUserId, Request $request): void
    {
        $invitation = $this->invitations->findById($invitationId);
        if ($invitation === null || (int) $invitation['tenant_id'] !== $tenant->tenantId) {
            throw new InvalidTokenException('That invitation was not found.');
        }
        if ($invitation['accepted_at'] !== null || $invitation['revoked_at'] !== null) {
            return;
        }

        $this->invitations->revoke($invitationId, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->record(new AuditEntry(
            action: AuditAction::INVITATION_REVOKED,
            tenantId: $tenant->tenantId,
            actorType: 'tenant_user',
            actorId: $actorUserId,
            targetType: 'invitation',
            targetId: (string) $invitationId,
            request: $request,
        ));
    }

    /** @return array<string, mixed> */
    public function preview(string $token): array
    {
        $invitation = $this->requireValid($token);

        return $invitation + [
            'roles' => $this->invitations->rolesForInvitation(
                (int) $invitation['tenant_id'],
                (int) $invitation['id'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function requireValid(string $token): array
    {
        if ($token === '') {
            throw new InvalidTokenException();
        }

        return $this->invitations->findValidByHash(SecureToken::hash($token))
            ?? throw new InvalidTokenException('This invitation is invalid, expired or already used.');
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int>
     * @throws ValidationException
     */
    private function requestedRoleIds(int $tenantId, array $input, MembershipContext $actor): array
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
        if (in_array('owner', array_values(array_intersect_key($available, array_flip($requested))), true) && !$isOwner) {
            throw new ValidationException(['role_ids' => 'Only the owner can grant the owner role.']);
        }

        if (!$isOwner) {
            // Invitees join with access to every branch (invitations carry no
            // branch scope), so a branch-limited member inviting anyone —
            // e.g. a second address of their own — would escalate to all
            // branches. Only members who already see every branch may invite.
            if ($actor->branchIds !== null) {
                throw new ValidationException(['email' => 'Only team members with access to every branch can send invitations.']);
            }
            // No vertical escalation: the requested roles may not carry any
            // permission the inviter does not hold themselves.
            $granted = $this->roles->permissionIdentifiersForRoles($tenantId, $requested);
            $beyond = array_values(array_diff($granted, $actor->permissions));
            if ($beyond !== []) {
                throw new ValidationException(['role_ids' => 'You cannot grant a role with permissions you do not have yourself.']);
            }
        }

        return $requested;
    }

    /** @param list<int> $roleIds */
    private function assignRoles(int $tenantId, int $tenantUserId, array $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            $this->roles->assignRole($tenantId, $tenantUserId, $roleId);
        }
    }

    private function actorName(int $userId): string
    {
        $actor = $this->users->findById($userId);

        return $actor === null
            ? 'An administrator'
            : trim((string) $actor['first_name'] . ' ' . (string) $actor['last_name']);
    }
}
