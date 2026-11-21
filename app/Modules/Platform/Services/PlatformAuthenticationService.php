<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Config\Config;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Modules\Auth\Exceptions\InvalidCredentialsException;
use App\Modules\Auth\Exceptions\TooManyAttemptsException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Platform\Dto\IssuedPlatformSession;
use App\Modules\Platform\Dto\PlatformLoginOutcome;
use App\Modules\Platform\Dto\ResolvedPlatformSession;
use App\Modules\Platform\Repositories\PlatformUserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\PasswordHasher;
use App\Shared\Security\RateLimiter;
use App\Shared\Support\TrustedProxies;
use App\Shared\Time\Clock;

/**
 * Admin authentication with the same password rules, IP ceilings and optional
 * network allowlist as tenants, plus mandatory MFA (auth.md §8c, §10).
 */
final class PlatformAuthenticationService
{
    private readonly TrustedProxies $allowlist;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly PlatformUserRepository $users,
        private readonly MfaRepository $mfa,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limiter,
        private readonly PlatformSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {
        /** @var list<string> $entries */
        $entries = $this->config->array('security.platform.ip_allowlist');
        $this->allowlist = new TrustedProxies(array_values(array_filter($entries, 'is_string')));
    }

    /** @throws HttpException */
    public function login(string $email, string $password, Request $request): PlatformLoginOutcome
    {
        $email = strtolower(trim($email));
        $ip = $request->clientIp() ?: 'unknown';

        if (!$this->networkAllowed($ip)) {
            throw new HttpException(403, 'PLATFORM_NETWORK_DENIED', 'Sign-in is not allowed from this network.');
        }

        $ipResult = $this->limiter->hit(
            'platform:login:ip:' . hash('sha256', $ip),
            $this->config->int('auth.login.ip_max_attempts', 50),
            $this->config->int('auth.login.ip_window_minutes', 15) * 60,
        );
        if (!$ipResult->allowed) {
            throw new TooManyAttemptsException($ipResult->retryAfterSeconds);
        }

        $user = $email === '' ? null : $this->users->findByEmail($email);
        if ($user === null) {
            $this->hasher->verify($password, $this->hasher->dummyHash());
            $this->auditFailed(null, $email, $request);
            throw new InvalidCredentialsException();
        }

        if ((string) $user['status'] !== 'active' || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->auditFailed((int) $user['id'], $email, $request);
            throw new InvalidCredentialsException();
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->users->updatePasswordHash((int) $user['id'], $this->hasher->hash($password));
        }

        $session = $this->sessions->issue((int) $user['id'], $request, false);
        $this->users->touchLastLogin((int) $user['id'], $this->clock->now()->format('Y-m-d H:i:s'));

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLATFORM_LOGIN_SUCCEEDED,
            actorType: 'platform_user',
            actorId: (int) $user['id'],
            targetType: 'platform_user',
            targetId: (string) $user['id'],
            request: $request,
        ));

        return new PlatformLoginOutcome(
            platformUserId: (int) $user['id'],
            session: $session,
            mfaEnrolled: $this->mfa->hasConfirmedFactor(MfaSubject::PlatformUser, (int) $user['id']),
        );
    }

    /**
     * Called after a valid TOTP or recovery code: marks the factor enrolled,
     * the session verified, and regenerates the session token.
     */
    public function completeMfa(ResolvedPlatformSession $session): IssuedPlatformSession
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->users->markMfaEnrolled($session->userId, $now);
        $this->sessions->markMfaVerified($session->sessionId);

        return $this->sessions->rotate($session);
    }

    public function logout(ResolvedPlatformSession $session, Request $request): void
    {
        $this->sessions->revoke($session->sessionId);

        $this->audit->record(new AuditEntry(
            action: AuditAction::PLATFORM_LOGOUT,
            actorType: 'platform_user',
            actorId: $session->userId,
            targetType: 'platform_user',
            targetId: (string) $session->userId,
            request: $request,
        ));
    }

    public function networkAllowed(string $ip): bool
    {
        $entries = $this->config->array('security.platform.ip_allowlist');

        return $entries === [] || $this->allowlist->isTrusted($ip);
    }

    private function auditFailed(?int $platformUserId, string $email, Request $request): void
    {
        $this->audit->record(new AuditEntry(
            action: AuditAction::PLATFORM_LOGIN_FAILED,
            actorType: $platformUserId === null ? 'system' : 'platform_user',
            actorId: $platformUserId,
            targetType: $platformUserId === null ? null : 'platform_user',
            targetId: $platformUserId === null ? null : (string) $platformUserId,
            after: ['email' => $email],
            request: $request,
        ));
    }
}
