<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Auth\Exceptions\InvalidTokenException;
use App\Modules\Auth\Repositories\PasswordResetTokenRepository;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Security\RateLimiter;
use App\Shared\Security\SecureToken;
use App\Shared\Time\Clock;

/**
 * Forgot/reset password (auth.md §4, §8b). The request response is always the
 * same; only a real account with an active status receives mail.
 */
final class PasswordResetService
{
    private const GENERIC_MESSAGE = 'If an account exists for that email, we have sent a reset link.';

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly SessionRepository $sessions,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly RateLimiter $limiter,
        private readonly AuthMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    public function genericMessage(): string
    {
        return self::GENERIC_MESSAGE;
    }

    /** Never reveals whether the address exists. */
    public function request(string $email, Request $request): void
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $emailKey = 'reset:email:' . hash('sha256', $email);
        $ipKey = 'reset:ip:' . hash('sha256', $request->clientIp() ?: 'unknown');
        $limit = $this->config->int('auth.login.reset_max_per_hour', 5);
        if (
            !$this->limiter->hit($emailKey, $limit, 3600)->allowed
            || !$this->limiter->hit($ipKey, $limit * 4, 3600)->allowed
        ) {
            return;
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || (string) $user['status'] !== 'active') {
            return;
        }

        $token = SecureToken::generate();
        $expiresAt = $this->clock->now()
            ->modify('+' . $this->config->int('auth.tokens.password_reset_minutes', 60) . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->tokens->invalidateForUser((int) $user['id']);
        $this->tokens->create((int) $user['id'], SecureToken::hash($token), $expiresAt);
        $this->mailer->sendPasswordReset($email, (string) $user['first_name'], $token, $request);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PASSWORD_RESET_REQUESTED,
            actorType: 'tenant_user',
            actorId: (int) $user['id'],
            targetType: 'user',
            targetId: (string) $user['id'],
            request: $request,
        ));
    }

    /**
     * @return string the account email
     * @throws ValidationException|InvalidTokenException
     */
    public function reset(string $token, string $password, string $confirmation, Request $request): string
    {
        $this->assertPassword($password, $confirmation);

        $row = $this->tokens->findValid(SecureToken::hash($token));
        if ($row === null) {
            throw new InvalidTokenException();
        }

        $userId = (int) $row['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null || (string) $user['status'] !== 'active') {
            throw new InvalidTokenException();
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->tokens->consume((int) $row['id'], $now);
        $this->tokens->invalidateForUser($userId);
        $this->users->updatePasswordHash($userId, $this->hasher->hash($password));
        $this->sessions->revokeAllForUser($userId, $now);
        $this->rememberTokens->revokeAllForUser($userId, $now);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PASSWORD_RESET,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'user',
            targetId: (string) $userId,
            request: $request,
        ));
        $this->mailer->sendPasswordChanged((string) $user['email'], (string) $user['first_name']);

        return (string) $user['email'];
    }

    /** @throws ValidationException */
    private function assertPassword(string $password, string $confirmation): void
    {
        $errors = [];
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        }
        foreach ($this->policy->violations($password) as $violation) {
            $errors['password'] = $violation;
            break;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
