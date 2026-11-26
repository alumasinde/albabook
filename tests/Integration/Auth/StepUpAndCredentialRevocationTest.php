<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Modules\Api\Repositories\ApiCredentialRepository;
use App\Modules\Api\Services\ApiCredentialRevoker;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Services\AuthMailer;
use App\Modules\Auth\Services\PasswordChangeService;
use App\Modules\Auth\Services\StepUpService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\AppUrl;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use Tests\Integration\Support\Phase2TestCase;
use Tests\Support\RecordingMailer;

/**
 * Sensitive actions must be re-confirmed: a fresh MFA verification inside the
 * step-up window, or the current password. Credentials a user minted die with
 * their password.
 */
final class StepUpAndCredentialRevocationTest extends Phase2TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private StepUpService $stepUp;
    private ApiCredentialRepository $credentials;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());
        $hasher = new PasswordHasher();
        $this->credentials = new ApiCredentialRepository($this->db);
        $this->userId = $this->createUser($this->uniqueEmail('stepup'));
        $this->users->updatePasswordHash($this->userId, $hasher->hash(self::PASSWORD));

        $passwords = new PasswordChangeService(
            $this->clock,
            $this->users,
            new SessionRepository($this->db),
            new RememberTokenRepository($this->db),
            $hasher,
            new PasswordPolicy($this->config, dirname(__DIR__, 3)),
            new AuthMailer(new RecordingMailer(), new AppUrl($this->config), $this->config),
            $audit,
            new ApiCredentialRevoker($this->credentials, $audit),
        );

        $this->stepUp = new StepUpService($this->config, $this->clock, $passwords);
    }

    public function testWithoutFreshMfaTheCurrentPasswordIsRequired(): void
    {
        $bare = $this->request([], null);
        self::assertNotNull($this->stepUp->confirm($bare, $this->userId), 'no password means no step-up');

        $wrong = $this->request(['current_password' => 'not the password'], null);
        self::assertSame('That password is not correct.', $this->stepUp->confirm($wrong, $this->userId));

        $right = $this->request(['current_password' => self::PASSWORD], null);
        self::assertNull($this->stepUp->confirm($right, $this->userId));
    }

    public function testAFreshMfaVerificationSatisfiesTheWindowUntilItGoesStale(): void
    {
        $fresh = $this->request([], $this->clock->now()->modify('-2 minutes'));
        self::assertNull($this->stepUp->confirm($fresh, $this->userId), 'inside the step-up window');

        $this->clock->advance('+30 minutes');
        self::assertNotNull($this->stepUp->confirm($fresh, $this->userId), 'stale MFA is not a step-up');
    }

    public function testRevokingForAUserKillsTheirLiveCredentialsAndIsAudited(): void
    {
        $tenantId = $this->provision($this->userId)['tenant_id'];
        $otherUserId = $this->createUser($this->uniqueEmail('other'));
        $mine = $this->credential($tenantId, $this->userId);
        $mineToo = $this->credential($tenantId, $this->userId);
        $theirs = $this->credential($tenantId, $otherUserId);

        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());
        $revoker = new ApiCredentialRevoker($this->credentials, $audit);
        self::assertSame(2, $revoker->revokeForUser($this->userId, $this->request([])));
        self::assertSame(0, $revoker->revokeForUser($this->userId, $this->request([])), 'already revoked');

        $stmt = $this->pdo->prepare('SELECT uuid, revoked_at FROM api_credentials WHERE uuid IN (:a, :b, :c)');
        $stmt->execute(['a' => $mine, 'b' => $mineToo, 'c' => $theirs]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['uuid']] = $row['revoked_at'];
        }

        self::assertNotNull($rows[$mine]);
        self::assertNotNull($rows[$mineToo]);
        self::assertNull($rows[$theirs], 'another user\'s credential is untouched');

        $auditStmt = $this->pdo->prepare(
            'SELECT actor_type FROM audit_logs WHERE action = :action',
        );
        $auditStmt->execute(['action' => AuditAction::API_CREDENTIALS_REVOKED]);
        self::assertSame(['tenant_user'], array_values($auditStmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $body */
    private function request(array $body, ?\DateTimeImmutable $mfaVerifiedAt = null): Request
    {
        $request = Request::create('POST', 'http://app.albabook.test/api/integrations/credentials', $body);
        if ($mfaVerifiedAt === null) {
            return $request;
        }

        return $request->withAttribute('auth_session', new ResolvedSession(
            sessionId: 1,
            userId: $this->userId,
            userUuid: $this->uuids->generate(),
            email: 'stepup@example.test',
            firstName: 'Test',
            lastName: 'Person',
            emailVerified: true,
            locale: 'en',
            activeTenantId: null,
            mfaVerified: true,
            mfaVerifiedAt: $mfaVerifiedAt,
            token: 'session-token',
            lastSeenAt: $this->clock->now(),
        ));
    }

    private function credential(int $tenantId, int $userId): string
    {
        $uuid = $this->uuids->generate();
        $this->credentials->insert([
            'uuid' => $uuid,
            'tenant_id' => $tenantId,
            'public_id' => bin2hex(random_bytes(16)),
            'secret_hash' => hash('sha256', bin2hex(random_bytes(24))),
            'scopes' => '["appointments:read"]',
            'expires_at' => null,
            'created_by_user_id' => $userId,
        ]);

        return $uuid;
    }
}
