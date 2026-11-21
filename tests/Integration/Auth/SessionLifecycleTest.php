<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Core\Http\Request;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Services\SessionService;
use App\Shared\Security\SessionCookieNames;
use Tests\Integration\Support\Phase2TestCase;

final class SessionLifecycleTest extends Phase2TestCase
{
    private const COOKIE = 'albabook_app';

    private SessionService $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = new SessionService(
            $this->config,
            $this->clock,
            new SessionRepository($this->db),
            new SessionCookieNames($this->config),
        );
    }

    public function testAnIssuedSessionResolvesFromItsCookie(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, false);

        $resolved = $this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token));

        self::assertNotNull($resolved);
        self::assertSame($issued->id, $resolved->sessionId);
        self::assertSame($userId, $resolved->userId);
        self::assertFalse($resolved->mfaVerified);

        $stored = $this->pdo->query('SELECT session_token_hash FROM user_sessions')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([hash('sha256', $issued->token)], array_values($stored), 'only the hash is stored');
    }

    public function testTheMfaFlagIsCarriedOnTheSession(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, true);

        $resolved = $this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token));

        self::assertNotNull($resolved);
        self::assertTrue($resolved->mfaVerified);
        self::assertNotNull($resolved->mfaVerifiedAt);
    }

    public function testAnUnknownCookieResolvesToNothing(): void
    {
        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, str_repeat('a', 64))));
        self::assertNull($this->sessions->resolve(Request::create('GET', 'http://app.albabook.test/dashboard')));
    }

    public function testIdleSessionsExpireAndAreRevoked(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, false);
        $this->pinTimestamps($issued->id);

        $this->clock->advance('+120 minutes');
        self::assertNotNull(
            $this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)),
            'exactly the idle limit is still valid',
        );
        $this->pinTimestamps($issued->id);

        $this->clock->advance('+121 minutes');
        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)));
        self::assertNotNull($this->revokedAt($issued->id));
    }

    public function testActivityExtendsIdleButNotAbsoluteLifetime(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, false);
        $this->pinTimestamps($issued->id);

        for ($i = 0; $i < 7; $i++) {
            $this->clock->advance('+100 minutes');
            self::assertNotNull(
                $this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)),
                '100 minutes of inactivity is under the idle limit',
            );
        }

        $this->clock->advance('+30 minutes');
        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)));
        self::assertNotNull($this->revokedAt($issued->id));
    }

    public function testRotationInvalidatesThePreviousToken(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, true);
        $resolved = $this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token));
        self::assertNotNull($resolved);

        $rotated = $this->sessions->rotate($resolved);

        self::assertSame($issued->id, $rotated->id);
        self::assertNotSame($issued->token, $rotated->token);
        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)));
        self::assertNotNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $rotated->token)));
    }

    public function testRevokingAllForAUserKeepsTheNominatedSession(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $first = $this->sessions->issue($userId, $this->loginRequest(), null, true);
        $second = $this->sessions->issue($userId, $this->loginRequest(), null, true);

        $this->sessions->revokeAllForUser($userId, $first->id);

        self::assertNotNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $first->token)));
        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $second->token)));
        self::assertNotNull($this->revokedAt($second->id));
        self::assertNull($this->revokedAt($first->id));
    }

    public function testSuspendingTheUserRevokesEveryOpenSession(): void
    {
        $userId = $this->createUser($this->uniqueEmail('session'));
        $issued = $this->sessions->issue($userId, $this->loginRequest(), null, true);
        $this->pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = :id")->execute(['id' => $userId]);

        self::assertNull($this->sessions->resolve($this->requestWithCookie(self::COOKIE, $issued->token)));
        self::assertNotNull($this->revokedAt($issued->id));
    }

    private function loginRequest(): Request
    {
        return Request::create('POST', 'http://app.albabook.test/login');
    }

    private function pinTimestamps(int $sessionId): void
    {
        $at = $this->clock->now()->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE user_sessions SET created_at = :created, last_seen_at = :seen WHERE id = :id')
            ->execute(['created' => $at, 'seen' => $at, 'id' => $sessionId]);
    }

    private function revokedAt(int $sessionId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT revoked_at FROM user_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }
}
