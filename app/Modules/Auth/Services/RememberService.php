<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\IssuedToken;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Shared\Security\SecureToken;
use App\Shared\Security\SessionCookieNames;
use App\Shared\Time\Clock;

/**
 * "Remember me" is a separate long-lived token, rotated on every use and never
 * a substitute for MFA (auth.md §8a). Restoring it creates a fresh server-side
 * session that still lacks mfa_verified_at when the user has a factor.
 */
final class RememberService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly RememberTokenRepository $tokens,
        private readonly SessionCookieNames $cookieNames,
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

        $row = $this->tokens->findActiveByHash(SecureToken::hash($token));
        if ($row === null) {
            return null;
        }

        if ((string) $row['user_status'] !== 'active') {
            $this->tokens->revoke((int) $row['remember_id'], $this->now());

            return null;
        }

        $replacement = SecureToken::generate();
        $expiresAt = (new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC')))->getTimestamp();
        $this->tokens->rotate((int) $row['remember_id'], SecureToken::hash($replacement), gmdate('Y-m-d H:i:s', $expiresAt));

        return [
            'user_id' => (int) $row['user_id'],
            'token' => $replacement,
            'expires_at' => $expiresAt,
        ];
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
