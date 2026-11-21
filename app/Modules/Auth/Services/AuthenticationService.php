<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\IssuedToken;
use App\Modules\Auth\Dto\LoginOutcome;
use App\Modules\Auth\Dto\RememberedLogin;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\LoginAttemptRepository;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\RateLimiter;
use App\Shared\Time\Clock;

/**
 * Password login with IP ceilings and soft per-account delays (auth.md §8b).
 * A session is issued even when a TOTP code is still outstanding; the session
 * simply has no mfa_verified_at until MfaService completes.
 */
final class AuthenticationService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $attempts,
        private readonly MfaRepository $mfa,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly SessionService $sessions,
        private readonly RememberService $remember,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws InvalidCredentialsException|TooManyAttemptsException */
    public function login(string $email, string $password, Request $request): LoginOutcome
    {
        $email = strtolower(trim($email));
        $ip = $request->clientIp() ?: 'unknown';

        $ipResult = $this->limiter->hit(
            'login:ip:' . hash('sha256', $ip),
            $this->config->int('auth.login.ip_max_attempts', 50),
            $this->config->int('auth.login.ip_window_minutes', 15) * 60,
        );
        if (!$ipResult->allowed) {
            throw new TooManyAttemptsException($ipResult->retryAfterSeconds);
        }

        $this->delayAfterFailures($email);

        $userAgent = (string) $request->header('user-agent', '');
        $user = $email === '' ? null : $this->users->findByEmail($email);

        if ($user === null) {
            $this->hasher->verify($password, $this->hasher->dummyHash());
            $this->recordFailure($email, $ip, $userAgent, null, $request);
            throw new InvalidCredentialsException();
        }

        if ((string) $user['status'] !== 'active' || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->recordFailure($email, $ip, $userAgent, (int) $user['id'], $request);
            throw new InvalidCredentialsException();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }

        $mfaPending = $this->mfa->hasConfirmedFactor(MfaSubject::User, (int) $user['id']);
        $session = $this->sessions->issue((int) $user['id'], $request, null, !$mfaPending);

        $this->attempts->record($email, $ip, true, $userAgent);
        $this->users->touchLastLogin((int) $user['id'], $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->record(new AuditEntry(
            action: AuditAction::LOGIN_SUCCEEDED,
            actorType: 'tenant_user',
            actorId: (int) $user['id'],
            targetType: 'user',
            targetId: (string) $user['id'],
            after: ['mfa_pending' => $mfaPending],
            request: $request,
        ));

        return new LoginOutcome((int) $user['id'], $session, $mfaPending);
    }

    /** Restores a remembered device into a new, unverified-for-MFA session. */
    public function resumeFromRemember(Request $request): ?RememberedLogin
    {
        $restored = $this->remember->restore($request);
        if ($restored === null) {
            return null;
        }

        $userId = $restored['user_id'];
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        $now = $this->clock->now();
        $issued = $this->sessions->issue($userId, $request, null, false);

        return new RememberedLogin(
            session: new ResolvedSession(
                sessionId: $issued->id,
                userId: $userId,
                userUuid: (string) $user['uuid'],
                email: (string) $user['email'],
                firstName: (string) $user['first_name'],
                lastName: (string) $user['last_name'],
                emailVerified: $user['email_verified_at'] !== null,
                locale: (string) $user['locale'],
                activeTenantId: null,
                mfaVerified: false,
                mfaVerifiedAt: null,
                token: $issued->token,
                lastSeenAt: $now,
            ),
            remember: new IssuedToken($restored['token'], $restored['expires_at']),
        );
    }

    public function logout(ResolvedSession $session, Request $request): void
    {
        $this->sessions->revoke($session->sessionId);
        $this->remember->revokeFromRequest($request);

        $this->audit->record(new AuditEntry(
            action: AuditAction::LOGOUT,
            actorType: 'tenant_user',
            actorId: $session->userId,
            targetType: 'user',
            targetId: (string) $session->userId,
            request: $request,
        ));
    }

    private function delayAfterFailures(string $email): void
    {
        if ($email === '') {
            return;
        }

        $threshold = $this->config->int('auth.login.email_failures_before_delay', 5);
        $failures = $this->attempts->recentFailuresForEmail(
            $email,
            $this->config->int('auth.login.email_window_minutes', 15),
        );
        if ($failures < $threshold) {
            return;
        }

        $step = $this->config->int('auth.login.email_delay_step_ms', 250);
        $ceiling = $this->config->int('auth.login.email_delay_max_ms', 2000);
        $delayMs = min($ceiling, $step * ($failures - $threshold + 1));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function recordFailure(
        string $email,
        string $ip,
        string $userAgent,
        ?int $userId,
        Request $request,
    ): void {
        $this->attempts->record($email, $ip, false, $userAgent);
        $this->audit->record(new AuditEntry(
            action: AuditAction::LOGIN_FAILED,
            actorType: $userId === null ? 'anonymous' : 'user',
            actorId: $userId,
            targetType: $userId === null ? null : 'user',
            targetId: $userId === null ? null : (string) $userId,
            after: ['email' => $email],
            request: $request,
        ));
    }
}
