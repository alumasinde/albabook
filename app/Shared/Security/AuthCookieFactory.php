<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Core\Http\Cookie;
use App\Core\Http\Request;

/**
 * Builds and clears the host-only authentication cookies. HttpOnly and
 * SameSite=Lax always; Secure whenever the request is (which is when the
 * __Host- names are used).
 */
final class AuthCookieFactory
{
    public function __construct(private readonly SessionCookieNames $names) {}

    public function userSession(Request $request, string $token, int $expiresAt): Cookie
    {
        return $this->cookie($this->names->user($request), $token, $expiresAt, $request);
    }

    public function clearUserSession(Request $request): Cookie
    {
        return $this->cookie($this->names->user($request), '', time() - 3600, $request);
    }

    public function platformSession(Request $request, string $token, int $expiresAt): Cookie
    {
        return $this->cookie($this->names->platform($request), $token, $expiresAt, $request);
    }

    public function clearPlatformSession(Request $request): Cookie
    {
        return $this->cookie($this->names->platform($request), '', time() - 3600, $request);
    }

    public function remember(Request $request, string $token, int $expiresAt): Cookie
    {
        return $this->cookie($this->names->remember($request), $token, $expiresAt, $request);
    }

    public function clearRemember(Request $request): Cookie
    {
        return $this->cookie($this->names->remember($request), '', time() - 3600, $request);
    }

    public function support(Request $request, string $token, int $expiresAt): Cookie
    {
        return $this->cookie($this->names->support($request), $token, $expiresAt, $request);
    }

    public function clearSupport(Request $request): Cookie
    {
        return $this->cookie($this->names->support($request), '', time() - 3600, $request);
    }

    private function cookie(string $name, string $value, int $expiresAt, Request $request): Cookie
    {
        return new Cookie(
            name: $name,
            value: $value,
            expiresAt: $expiresAt,
            secure: $request->isSecure(),
            httpOnly: true,
            sameSite: 'Lax',
        );
    }
}
