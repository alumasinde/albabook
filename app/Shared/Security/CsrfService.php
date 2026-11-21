<?php

declare(strict_types=1);

namespace App\Shared\Security;

use App\Shared\Support\TokenGenerator;

/**
 * Signed double-submit CSRF tokens (OWASP CSRF cheat sheet).
 *
 * The token is "<random>.<hmac>" where hmac = HMAC-SHA256(APP_KEY, binding|random).
 * It lives in a host-only cookie and is echoed in the form field or the
 * X-CSRF-Token header; both must match and the signature must verify, so an
 * attacker who can plant a cookie (e.g. from a sibling subdomain) still cannot
 * forge one.
 *
 * Phase 1 binding is empty. Phase 2 binds tokens to the server-side session id
 * (auth.md §8a) by passing it as $binding, without changing callers.
 */
final class CsrfService
{
    public function __construct(
        private readonly string $key,
        private readonly TokenGenerator $tokens,
    ) {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('CSRF key must be 32 bytes.');
        }
    }

    public function issue(string $binding = ''): string
    {
        $random = $this->tokens->randomToken(32);

        return $random . '.' . $this->sign($random, $binding);
    }

    public function isValidToken(string $token, string $binding = ''): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        return hash_equals($this->sign($parts[0], $binding), $parts[1]);
    }

    /** Cookie token must be authentic, and the submitted copy must equal it. */
    public function verify(?string $cookieToken, ?string $submittedToken, string $binding = ''): bool
    {
        if ($cookieToken === null || $submittedToken === null || $cookieToken === '' || $submittedToken === '') {
            return false;
        }

        return hash_equals($cookieToken, $submittedToken) && $this->isValidToken($cookieToken, $binding);
    }

    private function sign(string $random, string $binding): string
    {
        return TokenGenerator::base64Url(hash_hmac('sha256', "csrf|{$binding}|{$random}", $this->key, true));
    }
}
