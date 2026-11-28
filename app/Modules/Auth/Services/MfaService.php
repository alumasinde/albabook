<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\MfaEnrollment;
use App\Modules\Auth\Exceptions\MfaFailedException;
use App\Modules\Auth\Mfa\MfaSubject;
use App\Modules\Auth\Repositories\MfaRepository;
use App\Modules\Auth\Repositories\UserRepository;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditLogger;
use App\Shared\Security\Encrypter;
use App\Shared\Security\SecureToken;
use App\Shared\Security\TotpService;
use App\Shared\Time\Clock;

/**
 * TOTP enrolment and verification (auth.md §8c). Secrets are encrypted with
 * APP_KEY; recovery codes are shown once and stored hashed. A used code can
 * never be replayed, even across sessions.
 */
final class MfaService
{
    /**
     * Failed verification attempts allowed on one pending session before it
     * is revoked (auth.md §8c: codes are guessable, attempts must be capped).
     */
    public const MAX_ATTEMPTS = 5;

    /** Per-user cumulative cap: this many TOTP failures across sessions locks
     * verification for USER_LOCK_MINUTES — re-logging in cannot reset it. */
    public const USER_LOCK_ATTEMPTS = 10;
    public const USER_LOCK_MINUTES = 15;
    /** Failures are counted within this window from the first one; after it
     * lapses the next failure starts a fresh count (typos never accumulate
     * across days). */
    public const USER_FAILURE_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly MfaRepository $factors,
        private readonly TotpService $totp,
        private readonly Encrypter $encrypter,
        private readonly UserRepository $users,
        private readonly AuthMailer $mailer,
        private readonly AuditLogger $audit,
    ) {}

    public function hasConfirmedFactor(MfaSubject $subject, int $ownerId): bool
    {
        return $this->factors->hasConfirmedFactor($subject, $ownerId);
    }

    public function beginEnrollment(MfaSubject $subject, int $ownerId, string $accountLabel): MfaEnrollment
    {
        $this->factors->deleteUnconfirmedFactors($subject, $ownerId);

        $secret = $this->totp->generateSecret();
        $factorId = $this->factors->createFactor(
            $subject,
            $ownerId,
            $this->encrypter->encrypt($secret),
            str_contains($accountLabel, '@') ? 'Authenticator app' : null,
        );

        return new MfaEnrollment($factorId, $secret, $this->totp->provisioningUri($secret, $accountLabel));
    }

    /**
     * @return list<string> plain recovery codes, shown to the user once
     * @throws MfaFailedException
     */
    public function confirmEnrollment(
        MfaSubject $subject,
        int $ownerId,
        int $factorId,
        string $code,
        ?Request $request = null,
    ): array {
        $factor = $this->factors->findFactor($subject, $ownerId, $factorId);
        if ($factor === null || $factor['confirmed_at'] !== null) {
            throw new MfaFailedException('Start two-factor setup again.');
        }

        $step = $this->totp->verify(
            $this->encrypter->decrypt((string) $factor['secret_encrypted']),
            $code,
            $this->clock->now()->getTimestamp(),
            $factor['last_used_step'] === null ? null : (int) $factor['last_used_step'],
        );
        if ($step === null) {
            throw new MfaFailedException();
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->factors->confirmFactor($subject, $factorId, $now);
        $this->factors->recordUsedStep($subject, $factorId, $step);

        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $this->config->int('auth.mfa.recovery_codes', 10); $i++) {
            $recovery = SecureToken::recoveryCode();
            $plain[] = $recovery;
            $hashes[] = SecureToken::hashRecoveryCode($recovery);
        }
        $this->factors->replaceRecoveryCodes($subject, $ownerId, $hashes);

        $this->audit->record(new AuditEntry(
            action: $subject === MfaSubject::PlatformUser
                ? AuditAction::PLATFORM_MFA_ENROLLED
                : AuditAction::MFA_ENROLLED,
            actorType: $subject === MfaSubject::PlatformUser ? 'platform_user' : 'tenant_user',
            actorId: $ownerId,
            targetType: $subject === MfaSubject::PlatformUser ? 'platform_user' : 'user',
            targetId: (string) $ownerId,
            request: $request,
        ));

        return $plain;
    }

    public function verify(MfaSubject $subject, int $ownerId, string $code, ?Request $request = null): bool
    {
        $factor = $this->factors->confirmedFactor($subject, $ownerId);
        if ($factor === null) {
            return false;
        }

        $step = $this->totp->verify(
            $this->encrypter->decrypt((string) $factor['secret_encrypted']),
            $code,
            $this->clock->now()->getTimestamp(),
            $factor['last_used_step'] === null ? null : (int) $factor['last_used_step'],
        );
        if ($step !== null) {
            $this->factors->recordUsedStep($subject, $factor['id'], $step);

            return true;
        }

        $consumed = $this->factors->consumeRecoveryCode(
            $subject,
            $ownerId,
            SecureToken::hashRecoveryCode($code),
            $this->clock->now()->format('Y-m-d H:i:s'),
        );
        if ($consumed) {
            $this->audit->record(new AuditEntry(
                action: AuditAction::MFA_RECOVERY_USED,
                actorType: $subject === MfaSubject::PlatformUser ? 'platform_user' : 'tenant_user',
                actorId: $ownerId,
                targetType: 'recovery_code',
                targetId: null,
                request: $request,
            ));
        }

        return $consumed;
    }

    public function unusedRecoveryCodes(MfaSubject $subject, int $ownerId): int
    {
        return $this->factors->unusedRecoveryCodeCount($subject, $ownerId);
    }

    public function disable(MfaSubject $subject, int $ownerId, ?Request $request = null): void
    {
        $factor = $this->factors->confirmedFactor($subject, $ownerId);
        if ($factor === null) {
            return;
        }

        $this->factors->deleteFactor($subject, $ownerId, (int) $factor['id']);
        $this->factors->replaceRecoveryCodes($subject, $ownerId, []);

        $this->audit->record(new AuditEntry(
            action: AuditAction::MFA_DISABLED,
            actorType: $subject === MfaSubject::PlatformUser ? 'platform_user' : 'tenant_user',
            actorId: $ownerId,
            targetType: $subject === MfaSubject::PlatformUser ? 'platform_user' : 'user',
            targetId: (string) $ownerId,
            request: $request,
        ));

        if ($subject === MfaSubject::User) {
            $user = $this->users->findById($ownerId);
            if ($user !== null) {
                $this->mailer->sendMfaDisabled((string) $user['email'], (string) $user['first_name']);
            }
        }
    }
}
