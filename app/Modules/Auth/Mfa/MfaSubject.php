<?php

declare(strict_types=1);

namespace App\Modules\Auth\Mfa;

/**
 * Tenant users and platform administrators have separate factor tables
 * (002 vs 001) with identical shapes; the subject selects them at runtime.
 */
enum MfaSubject: string
{
    case User = 'user';
    case PlatformUser = 'platform_user';

    public function factorTable(): string
    {
        return $this === self::User ? 'user_mfa_factors' : 'platform_user_mfa_factors';
    }

    public function recoveryTable(): string
    {
        return $this === self::User ? 'user_recovery_codes' : 'platform_user_recovery_codes';
    }

    public function ownerColumn(): string
    {
        return $this === self::User ? 'user_id' : 'platform_user_id';
    }
}
