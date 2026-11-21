<?php

declare(strict_types=1);

namespace App\Modules\Platform\Context;

/**
 * Request-scoped platform administrator identity. Permissions are resolved
 * from platform_user_permissions only, never from tenant roles.
 */
final class PlatformAuthContext
{
    public ?PlatformUser $user = null;

    /** @var list<string> */
    public array $permissions = [];

    public bool $mfaPending = false;

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function reset(): void
    {
        $this->user = null;
        $this->permissions = [];
        $this->mfaPending = false;
    }
}
