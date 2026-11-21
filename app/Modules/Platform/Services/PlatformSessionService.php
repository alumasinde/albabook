<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Platform\Dto\IssuedPlatformSession;
use App\Modules\Platform\Dto\ResolvedPlatformSession;
use App\Modules\Platform\Repositories\PlatformSessionRepository;
use App\Shared\Security\SecureToken;
use App\Shared\Security\SessionCookieNames;
use App\Shared\Time\Clock;

/**
 * Admin sessions are separate rows, separate cookie and separate timeouts
 * (auth.md §10). A platform session never carries a tenant membership.
 */
final class PlatformSessionService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly PlatformSessionRepository $sessions,
        private readonly SessionCookieNames $cookieNames,
    ) {}

    public function issue(int $platformUserId, Request $request, bool $mfaVerified): IssuedPlatformSession
    {
        $token = SecureToken::generate();
        $now = $this->clock->now();
        $sessionId = $this->sessions->create(
            $platformUserId,
            SecureToken::hash($token),
            $request->clientIp(),
            (string) $request->header('user-agent', ''),
            $mfaVerified ? $now->format('Y-m-d H:i:s') : null,
        );

        return new IssuedPlatformSession($sessionId, $token, $this->expiresAt($now));
    }

    public function resolve(Request $request): ?ResolvedPlatformSession
    {
        $token = $request->cookie($this->cookieNames->platform($request));
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
        $lastSeen = new \DateTimeImmutable((string) $row['last_seen_at'], new \DateTimeZone('UTC'));
        $created = new \DateTimeImmutable((string) $row['session_created_at'], new \DateTimeZone('UTC'));

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

        return new ResolvedPlatformSession(
            sessionId: (int) $row['session_id'],
            userId: (int) $row['platform_user_id'],
            userUuid: (string) $row['platform_user_uuid'],
            email: (string) $row['email'],
            firstName: (string) $row['first_name'],
            lastName: (string) $row['last_name'],
            mfaVerified: $row['mfa_verified_at'] !== null,
            mfaEnrolled: $row['mfa_enrolled_at'] !== null,
            token: $token,
            lastSeenAt: $lastSeen,
        );
    }

    public function rotate(ResolvedPlatformSession $session): IssuedPlatformSession
    {
        $token = SecureToken::generate();
        $this->sessions->rotate($session->sessionId, SecureToken::hash($token));

        return new IssuedPlatformSession($session->sessionId, $token, $this->expiresAt($this->clock->now()));
    }

    public function markMfaVerified(int $sessionId): void
    {
        $this->sessions->markMfaVerified($sessionId, $this->clock->now()->format('Y-m-d H:i:s'));
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

    private function expiresAt(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() + $this->config->int('security.session.absolute_timeout_minutes', 720) * 60;
    }
}
