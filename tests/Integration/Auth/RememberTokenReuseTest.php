<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Services\RememberService;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\SessionCookieNames;
use Tests\Integration\Support\Phase2TestCase;

/**
 * Rotation appends a successor row, so a cookie replayed long after it was
 * spent is treated as theft and every remembered device and session is cut.
 * Rotation opens its own transaction, so no per-test rollback.
 */
final class RememberTokenReuseTest extends Phase2TestCase
{
    private RememberService $remember;
    private SessionCookieNames $cookies;

    protected function setUp(): void
    {
        $this->wrapInTransaction = false;
        parent::setUp();

        $this->cookies = new SessionCookieNames($this->config);
        $this->remember = new RememberService(
            $this->config,
            $this->clock,
            new RememberTokenRepository($this->db),
            $this->cookies,
            new SessionRepository($this->db),
            new AuditLogger($this->db, $this->clock, new RequestContext()),
        );
    }

    public function testRotationReplacesTheCookieAndALateReplayRevokesEverything(): void
    {
        $userId = $this->createUser($this->uniqueEmail('remember'));
        $first = $this->remember->issue($userId);

        $restored = $this->remember->restore($this->cookie($first->token));
        self::assertNotNull($restored);
        self::assertNotSame($first->token, $restored['token']);
        self::assertNotNull($this->usedAt($first->token), 'the spent row is kept for detection');

        // Parallel-request race: replay inside the grace window, no alarm.
        self::assertNull($this->remember->restore($this->cookie($first->token)));
        self::assertSame(0, $this->reuseAudits($userId));

        $this->clock->advance('+10 minutes');
        self::assertNull($this->remember->restore($this->cookie($first->token)));
        self::assertSame(1, $this->reuseAudits($userId), 'a late replay is recorded as theft');

        $active = $this->pdo->prepare(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :u AND revoked_at IS NULL',
        );
        $active->execute(['u' => $userId]);
        self::assertSame(0, (int) $active->fetchColumn(), 'the successor is revoked too');
    }

    public function testANaturalExpiryIsNotTreatedAsTheft(): void
    {
        $userId = $this->createUser($this->uniqueEmail('expired'));
        $issued = $this->remember->issue($userId);

        $this->clock->advance('+31 days');
        self::assertNull($this->remember->restore($this->cookie($issued->token)));
        self::assertSame(0, $this->reuseAudits($userId));
    }

    private function cookie(string $token): Request
    {
        return $this->requestWithCookie(
            $this->cookies->remember(Request::create('GET', 'http://app.albabook.test/dashboard')),
            $token,
        );
    }

    private function usedAt(string $token): mixed
    {
        $stmt = $this->pdo->prepare('SELECT used_at FROM remember_tokens WHERE token_hash = :h');
        $stmt->execute(['h' => hash('sha256', $token)]);

        return $stmt->fetchColumn();
    }

    private function reuseAudits(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM audit_logs WHERE action = :action AND actor_id = :actor',
        );
        $stmt->execute(['action' => AuditAction::REMEMBER_TOKEN_REUSED, 'actor' => $userId]);

        return (int) $stmt->fetchColumn();
    }
}
