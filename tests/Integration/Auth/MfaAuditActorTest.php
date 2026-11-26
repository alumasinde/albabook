<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Services\AuthMailer;
use App\Modules\Auth\Services\MfaService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Http\AppUrl;
use App\Shared\Security\Encrypter;
use App\Shared\Security\SecureToken;
use App\Shared\Security\TotpService;
use Tests\Integration\Support\Phase2TestCase;
use Tests\Support\RecordingMailer;

/**
 * Tenant MFA events must store an enum-valid actor type; "user" is rejected by
 * the audit_logs enum under strict SQL mode.
 */
final class MfaAuditActorTest extends Phase2TestCase
{
    private MfaService $mfa;
    private MfaRepository $factors;
    private Encrypter $encrypter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encrypter = new Encrypter($this->config->appKey());
        $this->factors = new MfaRepository($this->db);
        $this->mfa = new MfaService(
            $this->config,
            $this->clock,
            $this->factors,
            new TotpService($this->config),
            $this->encrypter,
            $this->users,
            new AuthMailer(new RecordingMailer(), new AppUrl($this->config), $this->config),
            new AuditLogger($this->db, $this->clock, new RequestContext()),
        );
    }

    public function testDisablingAFactorIsAuditedAsATenantUser(): void
    {
        $userId = $this->createUser($this->uniqueEmail('mfa-disable'));
        $this->confirmedFactor($userId);

        $this->mfa->disable(MfaSubject::User, $userId, $this->request());

        self::assertSame('tenant_user', $this->actorType(AuditAction::MFA_DISABLED, $userId));
    }

    public function testUsingARecoveryCodeIsAuditedAsATenantUser(): void
    {
        $userId = $this->createUser($this->uniqueEmail('mfa-recovery'));
        $this->confirmedFactor($userId);
        $code = SecureToken::recoveryCode();
        $this->factors->replaceRecoveryCodes(MfaSubject::User, $userId, [SecureToken::hashRecoveryCode($code)]);

        self::assertTrue($this->mfa->verify(MfaSubject::User, $userId, $code, $this->request()));
        self::assertSame('tenant_user', $this->actorType(AuditAction::MFA_RECOVERY_USED, $userId));
    }

    private function confirmedFactor(int $userId): void
    {
        $factorId = $this->factors->createFactor(
            MfaSubject::User,
            $userId,
            $this->encrypter->encrypt('JBSWY3DPEHPK3PXP'),
            null,
        );
        $this->factors->confirmFactor(MfaSubject::User, $factorId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    private function actorType(string $action, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT actor_type FROM audit_logs WHERE action = :action AND actor_id = :actor ORDER BY id DESC LIMIT 1',
        );
        $stmt->execute(['action' => $action, 'actor' => $userId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/security/mfa');
    }
}
