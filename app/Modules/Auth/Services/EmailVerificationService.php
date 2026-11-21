<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Repositories\UserRepository;
use App\Modules\Auth\Repositories\VerificationTokenRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\SecureToken;
use App\Shared\Time\Clock;

/**
 * Hashed, single-use, 24-hour verification tokens (auth.md §8d). Unverified
 * owners may continue onboarding but cannot publish or invite.
 */
final class EmailVerificationService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly VerificationTokenRepository $tokens,
        private readonly AuthMailer $mailer,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $user */
    public function issue(array $user, Request $request): void
    {
        $userId = (int) $user['id'];
        $token = SecureToken::generate();
        $expiresAt = $this->clock->now()
            ->modify('+' . $this->config->int('auth.tokens.verification_hours', 24) . ' hours')
            ->format('Y-m-d H:i:s');

        $this->tokens->invalidateForUser($userId);
        $this->tokens->create($userId, SecureToken::hash($token), $expiresAt);
        $this->mailer->sendVerification((string) $user['email'], (string) $user['first_name'], $token, $request);
    }

    /** @param array<string, mixed> $user */
    public function resend(array $user, Request $request): void
    {
        $key = 'verify:user:' . hash('sha256', (string) $user['id']);
        $result = $this->limiter->hit(
            $key,
            $this->config->int('auth.login.verification_resend_max_per_hour', 5),
            3600,
        );
        if (!$result->allowed) {
            throw new TooManyAttemptsException($result->retryAfterSeconds);
        }

        $this->issue($user, $request);
    }

    /**
     * @return array{user_id: int, email: string}
     * @throws \App\Modules\Auth\Exceptions\InvalidTokenException
     */
    public function verify(string $token, ?Request $request = null): array
    {
        $row = $this->tokens->findValid(SecureToken::hash($token));
        if ($row === null) {
            throw new \App\Modules\Auth\Exceptions\InvalidTokenException();
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $userId = (int) $row['user_id'];
        $this->tokens->consume((int) $row['id'], $now);
        $this->tokens->invalidateForUser($userId);
        $this->users->markEmailVerified($userId, $now);

        $user = $this->users->findById($userId);
        $this->audit->record(new AuditEntry(
            action: AuditAction::EMAIL_VERIFIED,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'user',
            targetId: (string) $userId,
            request: $request,
        ));

        return ['user_id' => $userId, 'email' => (string) ($user['email'] ?? '')];
    }
}
