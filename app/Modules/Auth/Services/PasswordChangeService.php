<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Repositories\RememberTokenRepository;
use App\Modules\Auth\Repositories\SessionRepository;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\PasswordPolicy;
use App\Shared\Time\Clock;

/**
 * Signed-in password change. Every other session and remembered device is
 * revoked; the current session is kept so the user is not signed out of the
 * page they just used (auth.md §8a).
 */
final class PasswordChangeService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly AuthMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ValidationException|InvalidCredentialsException
     */
    public function change(
        int $userId,
        int $currentSessionId,
        string $currentPassword,
        string $newPassword,
        string $confirmation,
        Request $request,
    ): void {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new InvalidCredentialsException();
        }
        if (!$this->hasher->verify($currentPassword, (string) $user['password_hash'])) {
            throw new ValidationException(['current_password' => 'That password is not correct.']);
        }
        if ($newPassword === $currentPassword) {
            throw new ValidationException(['password' => 'Choose a password you have not used here before.']);
        }

        $errors = [];
        if ($newPassword !== $confirmation) {
            $errors['password_confirmation'] = 'The passwords do not match.';
        }
        foreach ($this->policy->violations($newPassword) as $violation) {
            $errors['password'] = $violation;
            break;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->users->updatePasswordHash($userId, $this->hasher->hash($newPassword));
        $this->sessions->revokeAllForUser($userId, $now, $currentSessionId);
        $this->rememberTokens->revokeAllForUser($userId, $now);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PASSWORD_CHANGED,
            actorType: 'tenant_user',
            actorId: $userId,
            targetType: 'user',
            targetId: (string) $userId,
            request: $request,
        ));
        $this->mailer->sendPasswordChanged((string) $user['email'], (string) $user['first_name']);
    }

    /** Used by the security page before disabling MFA or other step-up actions. */
    public function verifyCurrentPassword(int $userId, string $password): bool
    {
        $user = $this->users->findById($userId);

        return $user !== null && $this->hasher->verify($password, (string) $user['password_hash']);
    }
}
