<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Modules\Auth\Context\MembershipContext;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use App\Modules\Auth\Context\AuthenticatedUser;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Repositories\InvitationRepository;
use App\Modules\Auth\Services\AuthMailer;
use App\Modules\Auth\Services\InvitationService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\AppUrl;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Tests\Integration\Support\Phase2TestCase;
use Tests\Support\RecordingMailer;

/**
 * Invitations bind one email, one tenant and a fixed role set. Repository
 * transactions mean no per-test rollback; every test uses fresh identities.
 */
final class InvitationRoleScopeTest extends Phase2TestCase
{
    private RecordingMailer $mailer;
    private InvitationService $invitations;

    protected function setUp(): void
    {
        $this->wrapInTransaction = false;
        parent::setUp();

        $this->mailer = new RecordingMailer();
        $resolver = new TenantResolver($this->tenants, $this->memberships);
        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());

        $this->invitations = new InvitationService(
            $this->config,
            $this->clock,
            $this->db,
            new Validator(),
            new InvitationRepository($this->db, $this->uuids),
            $this->users,
            $this->memberships,
            $this->roles,
            $resolver,
            new PasswordHasher(),
            new PasswordPolicy($this->config, dirname(__DIR__, 3)),
            new AuthMailer($this->mailer, new AppUrl($this->config), $this->config),
            $this->uuids,
            $audit,
        );
    }

    public function testANewUserAcceptsAndJoinsWithTheInvitedRolesOnly(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $tenant = $this->provision($ownerId);
        $email = $this->uniqueEmail('invitee');

        $this->invitations->invite(
            $this->tenantOf($ownerId, $tenant['tenant_id']),
            $this->actor($ownerId, $tenant['tenant_id'], $tenant['tenant_user_id'], ['owner']),
            ['email' => $email, 'role_ids' => [(string) $this->roleId($tenant['tenant_id'], 'receptionist')]],
            $this->request(),
        );

        $token = $this->tokenFromEmail();
        $accepted = $this->invitations->acceptAsNewUser($token, [
            'first_name' => 'Amina',
            'middle_name' => '',
            'last_name' => 'Otieno',
            'phone' => '',
            'password' => 'strange-new-password-42',
            'password_confirmation' => 'strange-new-password-42',
        ], $this->request());

        self::assertSame($email, $accepted['email']);
        $user = $this->users->findByEmail($email);
        self::assertNotNull($user);
        self::assertNotNull($user['email_verified_at'], 'the invitation link proved ownership');

        $membership = $this->memberships->findActive((int) $user['id'], $tenant['tenant_id']);
        self::assertNotNull($membership);
        self::assertSame(
            ['receptionist'],
            array_column($this->roles->roleSummariesForMembership($tenant['tenant_id'], (int) $membership['id']), 'slug'),
            'only the invited role is granted',
        );

        $invitationRow = $this->pdo->prepare('SELECT accepted_at FROM tenant_invitations WHERE email = :email');
        $invitationRow->execute(['email' => $email]);
        self::assertNotNull($invitationRow->fetchColumn());

        try {
            $this->invitations->acceptAsNewUser($token, [
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'password' => 'strange-new-password-42',
                'password_confirmation' => 'strange-new-password-42',
            ], $this->request());
            self::fail('An invitation must only be accepted once.');
        } catch (InvalidTokenException) {
            $this->addToAssertionCount(1);
        }

        $audit = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action AND tenant_id = :tenant_id');
        $audit->execute(['action' => AuditAction::INVITATION_ACCEPTED, 'tenant_id' => $tenant['tenant_id']]);
        self::assertSame(1, (int) $audit->fetchColumn());
    }

    public function testAnotherTenantsRoleCannotBeGranted(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $tenant = $this->provision($ownerId);
        $otherOwnerId = $this->createUser($this->uniqueEmail('owner'));
        $otherTenant = $this->provision($otherOwnerId);

        try {
            $this->invitations->invite(
                $this->tenantOf($ownerId, $tenant['tenant_id']),
                $this->actor($ownerId, $tenant['tenant_id'], $tenant['tenant_user_id'], ['owner']),
                ['email' => $this->uniqueEmail('invitee'), 'role_ids' => [(string) $this->roleId($otherTenant['tenant_id'], 'receptionist')]],
                $this->request(),
            );
            self::fail('Expected the foreign role id to be rejected.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('from this business', $e->errors['role_ids']);
        }

        self::assertCount(0, $this->mailer->messages, 'nothing is sent for a rejected invitation');
    }

    public function testOnlyAnOwnerCanGrantTheOwnerRole(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $tenant = $this->provision($ownerId);

        try {
            $this->invitations->invite(
                $this->tenantOf($ownerId, $tenant['tenant_id']),
                $this->actor($ownerId, $tenant['tenant_id'], $tenant['tenant_user_id'], ['business_administrator']),
                ['email' => $this->uniqueEmail('invitee'), 'role_ids' => [(string) $this->roleId($tenant['tenant_id'], 'owner')]],
                $this->request(),
            );
            self::fail('Expected a non-owner to be blocked from granting owner.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Only the owner', $e->errors['role_ids']);
        }
    }

    public function testAnExistingUserMustAcceptWithTheInvitedEmail(): void
    {
        $ownerId = $this->createUser($this->uniqueEmail('owner'));
        $tenant = $this->provision($ownerId);
        $invitedEmail = $this->uniqueEmail('invitee');

        $this->invitations->invite(
            $this->tenantOf($ownerId, $tenant['tenant_id']),
            $this->actor($ownerId, $tenant['tenant_id'], $tenant['tenant_user_id'], ['owner']),
            ['email' => $invitedEmail, 'role_ids' => [(string) $this->roleId($tenant['tenant_id'], 'staff')]],
            $this->request(),
        );

        $strangerEmail = $this->uniqueEmail('stranger');
        $strangerId = $this->createUser($strangerEmail);
        $stranger = new AuthenticatedUser(
            id: $strangerId,
            uuid: $this->uuids->generate(),
            email: $strangerEmail,
            firstName: 'Other',
            lastName: 'Person',
            emailVerified: true,
            locale: 'en',
            sessionId: 1,
            activeTenantId: null,
            mfaVerified: true,
            sessionToken: 'token',
        );

        try {
            $this->invitations->acceptAsExistingUser($this->tokenFromEmail(), $stranger, $this->request());
            self::fail('A different signed-in user must not accept the invitation.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('invited email', $e->errors['email']);
        }
    }

    private function tokenFromEmail(): string
    {
        $message = $this->mailer->last();
        self::assertNotNull($message, 'an invitation email should have been sent');
        self::assertSame(1, preg_match('#/invite/([a-f0-9]{64})#', $message->textBody, $matches), $message->textBody);

        return $matches[1] ?? self::fail('The invitation email has no token link.');
    }

    private function tenantOf(int $userId, int $tenantId): TenantContext
    {
        return (new TenantResolver($this->tenants, $this->memberships))->forUser($userId, $tenantId);
    }

    /** @param list<string> $roles */
    private function actor(int $userId, int $tenantId, int $tenantUserId, array $roles): MembershipContext
    {
        return new MembershipContext($tenantUserId, $userId, $tenantId, $roles, [], null);
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/team');
    }
}
