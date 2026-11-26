<?php

declare(strict_types=1);

namespace App\Modules\Auth\Services;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Modules\Auth\Dto\ResolvedSession;
use App\Shared\Time\Clock;

/**
 * Re-authentication for sensitive actions (API keys, secrets, destructive
 * changes). A recent MFA verification satisfies the window; otherwise the
 * caller must supply the account password with the request.
 */
final class StepUpService
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly PasswordChangeService $passwords,
    ) {}

    /**
     * @return string|null null when confirmed, otherwise the user-facing error
     */
    public function confirm(Request $request, int $userId): ?string
    {
        $session = $request->attribute('auth_session');
        if (
            $session instanceof ResolvedSession
            && $session->hasFreshMfa($this->config->int('auth.mfa.step_up_minutes', 10), $this->clock->now())
        ) {
            return null;
        }

        $password = (string) ($request->body()['current_password'] ?? '');
        if ($password === '') {
            return 'Confirm this action with your password.';
        }
        if (!$this->passwords->verifyCurrentPassword($userId, $password)) {
            return 'That password is not correct.';
        }

        return null;
    }
}
