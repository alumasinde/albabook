<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Config\Config;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;

/**
 * One place that knows which host-only cookie carries which session.
 *
 * The __Host- prefix (auth.md §8a) requires Secure, so plain HTTP local
 * development falls back to an unprefixed name. Host groups never share a
 * cookie: the tenant portal and the admin app authenticate separately.
 */
final class SessionCookieNames
{
    public function __construct(private readonly Config $config) {}

    public function user(Request $request): string
    {
        return $this->name('security.session.cookie_secure', 'security.session.cookie_insecure', $request);
    }

    public function platform(Request $request): string
    {
        return $this->name('security.platform.cookie_secure', 'security.platform.cookie_insecure', $request);
    }

    public function remember(Request $request): string
    {
        return $this->name('security.remember.cookie_secure', 'security.remember.cookie_insecure', $request);
    }

    public function support(Request $request): string
    {
        return $this->name('security.support.cookie_secure', 'security.support.cookie_insecure', $request);
    }

    /**
     * Binding material for CSRF tokens: the raw server-side session token
     * identifies the session without a database lookup (auth.md §8a).
     */
    public function csrfBinding(Request $request): string
    {
        $userToken = $request->cookie($this->user($request));
        if ($userToken !== null && $userToken !== '') {
            return 'app|' . hash('sha256', $userToken);
        }

        $platformToken = $request->cookie($this->platform($request));
        if ($platformToken !== null && $platformToken !== '') {
            return 'admin|' . hash('sha256', $platformToken);
        }

        if ($request->hostGroup() === HostGroup::Admin) {
            return 'admin|';
        }

        return 'app|';
    }

    private function name(string $secureKey, string $insecureKey, Request $request): string
    {
        return $request->isSecure()
            ? $this->config->string($secureKey)
            : $this->config->string($insecureKey);
    }
}
