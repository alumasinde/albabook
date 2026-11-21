<?php

declare(strict_types=1);

namespace App\Modules\Auth\Context;

/**
 * Request-scoped tenant-user identity established by AuthenticationMiddleware.
 * It carries no tenant permissions; those belong to MembershipContext.
 */
final class AuthContext
{
    public ?AuthenticatedUser $user = null;

    public bool $mfaPending = false;

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function reset(): void
    {
        $this->user = null;
        $this->mfaPending = false;
    }
}
