<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\IssuedToken;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\SecureToken;
use App\Shared\Security\SessionCookieNames;
use App\Shared\Time\Clock;

/**
 * "Remember me" is a separate long-lived token, rotated on every use and never
 * a substitute for MFA (auth.md §8a). Rotation appends a successor row, so a
 * spent cookie presented again is detected as a replay and revokes the
 * account's sessions and remembered devices.
 */
final class RememberService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly RememberTokenRepository $tokens,
        private readonly SessionCookieNames $cookieNames,
        private readonly SessionRepository $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function issue(int $userId): IssuedToken
    {
        $token = SecureToken::generate();
        $expiresAt = $this->clock->now()->getTimestamp() + $this->config->int('security.remember.lifetime_days', 30) * 86400;
        $this->tokens->create($userId, SecureToken::hash($token), gmdate('Y-m-d H:i:s', $expiresAt));

        return new IssuedToken($token, $expiresAt);
    }

    /**
     * @return array{user_id: int, token: string, expires_at: int}|null
     */
    public function restore(Request $request): ?array
    {
        $token = $request->cookie($this->cookieNames->remember($request));
        if ($token === null || $token === '') {
            return null;
        }

        $row = $this->tokens->findByHash(SecureToken::hash($token));
        if ($row === null) {
            return null;
        }

        $now = $this->now();
        $userId = (int) $row['user_id'];

        // A spent or revoked row presented again is a replay of the cookie.
        // Inside the grace window it is a parallel-request race; beyond it the
        // token was stolen, so every session and remembered device dies.
        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            $spentAt = (string) ($row['used_at'] ?? $row['revoked_at']);
            $cutoff = gmdate(
                'Y-m-d H:i:s',
                $this->clock->now()->getTimestamp() - $this->config->int('security.remember.reuse_grace_seconds', 60),
            );
            if ($spentAt < $cutoff) {
                $this->compromised($userId, $now, $request);
            }

            return null;
        }

        if ((string) $row['expires_at'] <= $now) {
            return null;
        }

        if ((string) $row['user_status'] !== 'active') {
            $this->tokens->revoke((int) $row['remember_id'], $now);

            return null;
        }

        $replacement = SecureToken::generate();
        $expiresAt = (string) $row['expires_at'];
        if (!$this->tokens->rotateChain(
            (int) $row['remember_id'],
            $userId,
            SecureToken::hash($replacement),
            $expiresAt,
            $now,
        )) {
            return null;
        }

        return [
            'user_id' => $userId,
            'token' => $replacement,
            'expires_at' => (new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')))->getTimestamp(),
        ];
    }

    /** Replayed remember token: assume theft and cut every live credential. */
    private function compromised(int $userId, string $now, Request $request): void
    {
        $this->tokens->revokeAllForUser($userId, $now);
        $this->sessions->revokeAllForUser($userId, $now);

        $this->audit->record(new AuditEntry(
            action: AuditAction::REMEMBER_TOKEN_REUSED,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'user',
            targetId: (string) $userId,
            request: $request,
        ));
    }

    public function revokeFromRequest(Request $request): void
    {
        $token = $request->cookie($this->cookieNames->remember($request));
        if ($token === null || $token === '') {
            return;
        }

        $row = $this->tokens->findActiveByHash(SecureToken::hash($token));
        if ($row !== null) {
            $this->tokens->revoke((int) $row['remember_id'], $this->now());
        }
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->tokens->revokeAllForUser($userId, $this->now());
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
