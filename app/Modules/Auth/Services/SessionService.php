<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\IssuedSession;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Shared\Security\SecureToken;
use App\Shared\Security\SessionCookieNames;
use App\Shared\Time\Clock;

/**
 * Server-side sessions only (auth.md §8a). Tokens are random 256-bit values;
 * the database stores the SHA-256 hash. Idle and absolute timeouts are enforced
 * here so every caller gets the same answer.
 */
final class SessionService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly SessionRepository $sessions,
        private readonly SessionCookieNames $cookieNames,
    ) {}

    public function issue(int $userId, Request $request, ?int $activeTenantId, bool $mfaVerified): IssuedSession
    {
        $token = SecureToken::generate();
        $now = $this->clock->now();
        $sessionId = $this->sessions->create(
            $userId,
            SecureToken::hash($token),
            $activeTenantId,
            $request->clientIp(),
            (string) $request->header('user-agent', ''),
            $mfaVerified ? $now->format('Y-m-d H:i:s') : null,
        );

        return new IssuedSession($sessionId, $token, $this->expiresAt($now));
    }

    public function resolve(Request $request): ?ResolvedSession
    {
        $token = $request->cookie($this->cookieNames->user($request));
        if ($token === null || $token === '') {
            return null;
        }

        $row = $this->sessions->findActiveByHash(SecureToken::hash($token));
        if ($row === null) {
            return null;
        }

        if ((string) $row['user_status'] !== 'active') {
            $this->revoke((int) $row['session_id']);

            return null;
        }

        $now = $this->clock->now();
        $lastSeen = $this->utc((string) $row['last_seen_at']);
        $created = $this->utc((string) $row['session_created_at']);

        if (
            $now->getTimestamp() - $lastSeen->getTimestamp() > $this->config->int('security.session.idle_timeout_minutes', 120) * 60
            || $now->getTimestamp() - $created->getTimestamp() > $this->config->int('security.session.absolute_timeout_minutes', 720) * 60
        ) {
            $this->revoke((int) $row['session_id']);

            return null;
        }

        $touchInterval = $this->config->int('security.session.touch_interval_seconds', 60);
        if ($now->getTimestamp() - $lastSeen->getTimestamp() >= $touchInterval) {
            $this->sessions->touch((int) $row['session_id'], $now->format('Y-m-d H:i:s'));
            $lastSeen = $now;
        }

        return new ResolvedSession(
            sessionId: (int) $row['session_id'],
            userId: (int) $row['user_id'],
            userUuid: (string) $row['user_uuid'],
            email: (string) $row['email'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            emailVerified: $row['email_verified_at'] !== null,
            locale: (string) $row['locale'],
            activeTenantId: $row['active_tenant_id'] === null ? null : (int) $row['active_tenant_id'],
            mfaVerified: $row['mfa_verified_at'] !== null,
            mfaVerifiedAt: $row['mfa_verified_at'] === null ? null : $this->utc((string) $row['mfa_verified_at']),
            token: $token,
            lastSeenAt: $lastSeen,
        );
    }

    /**
     * Regenerate the session id after MFA, a workspace switch or a privilege
     * change (auth.md §8a). The expiry is unchanged.
     */
    public function rotate(ResolvedSession $session): IssuedSession
    {
        $token = SecureToken::generate();
        $this->sessions->rotate($session->sessionId, SecureToken::hash($token));

        return new IssuedSession($session->sessionId, $token, $this->expiresAt($this->clock->now()));
    }

    public function markMfaVerified(int $sessionId): void
    {
        $this->sessions->markMfaVerified($sessionId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function setActiveTenant(int $sessionId, ?int $tenantId): void
    {
        $this->sessions->setActiveTenant($sessionId, $tenantId);
    }

    /** Count a failed two-factor attempt; returns the session's new total. */
    public function recordMfaFailure(int $sessionId): int
    {
        return $this->sessions->incrementMfaAttempts($sessionId);
    }

    public function revoke(int $sessionId): void
    {
        $this->sessions->revoke($sessionId, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function revokeAllForUser(int $userId, ?int $exceptSessionId = null): void
    {
        $this->sessions->revokeAllForUser($userId, $this->clock->now()->format('Y-m-d H:i:s'), $exceptSessionId);
    }

    private function expiresAt(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() + $this->config->int('security.session.absolute_timeout_minutes', 720) * 60;
    }

    private function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
