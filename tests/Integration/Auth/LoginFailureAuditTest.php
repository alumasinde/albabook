<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Repositories\LoginAttemptRepository;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Services\AuthenticationService;
use App\Modules\Auth\Services\RememberService;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\SessionCookieNames;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Failed logins must store an enum-valid actor type; strict SQL mode rejects
 * free-form values such as "anonymous".
 */
final class LoginFailureAuditTest extends Phase2TestCase
{
    private AuthenticationService $auth;
    private PasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new PasswordHasher();
        $cookies = new SessionCookieNames($this->config);
        $audit = new AuditLogger($this->db, $this->clock, new RequestContext());

        $this->auth = new AuthenticationService(
            $this->config,
            $this->clock,
            $this->users,
            new LoginAttemptRepository($this->db),
            new MfaRepository($this->db),
            $this->hasher,
            new RateLimiter($this->db, $this->clock),
            new SessionService($this->config, $this->clock, new SessionRepository($this->db), $cookies),
            new RememberService($this->config, $this->clock, new RememberTokenRepository($this->db), $cookies),
            $audit,
        );
    }

    public function testAnUnknownAddressIsAuditedAsSystem(): void
    {
        $email = $this->uniqueEmail('ghost');

        try {
            $this->auth->login($email, 'whatever-passphrase', $this->request());
            self::fail('Unknown credentials must be rejected.');
        } catch (InvalidCredentialsException) {
            $this->addToAssertionCount(1);
        }

        $rows = $this->failureRows();
        self::assertCount(1, $rows);
        self::assertSame('system', $rows[0]['actor_type']);
        self::assertNull($rows[0]['actor_id']);
    }

    public function testAKnownAccountIsAuditedAsATenantUser(): void
    {
        $email = $this->uniqueEmail('known');
        $userId = $this->createUser($email);
        $this->users->updatePasswordHash($userId, $this->hasher->hash('the-right-passphrase-1'));

        try {
            $this->auth->login($email, 'the-wrong-passphrase-1', $this->request());
            self::fail('A wrong password must be rejected.');
        } catch (InvalidCredentialsException) {
            $this->addToAssertionCount(1);
        }

        $rows = $this->failureRows();
        self::assertCount(1, $rows);
        self::assertSame('tenant_user', $rows[0]['actor_type']);
        self::assertSame($userId, (int) $rows[0]['actor_id']);
    }

    /** @return list<array<string, mixed>> */
    private function failureRows(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT actor_type, actor_id FROM audit_logs WHERE action = :action ORDER BY id',
        );
        $stmt->execute(['action' => AuditAction::LOGIN_FAILED]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return $rows;
    }

    private function request(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/login');
    }
}
