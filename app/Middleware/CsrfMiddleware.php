<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config\Config;
use App\Core\Http\Cookie;
use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Security\CsrfService;
use App\Shared\Security\SessionCookieNames;

/**
 * Protects state-changing browser requests (plan.md §19.3).
 *
 * - Safe methods: make sure the visitor has a valid token cookie and expose
 *   the token to views (csrf_field()).
 * - Unsafe methods: require the form field or X-CSRF-Token header to match the
 *   signed cookie, and reject a cross-origin Origin header outright.
 * - The API host is exempt: it authenticates with credentials, not cookies.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CsrfService $csrf,
        private readonly RequestContext $context,
        private readonly Config $config,
        private readonly SessionCookieNames $cookies,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $group = $request->hostGroup();
        if ($group === null || !$group->usesCsrf()) {
            return $next($request);
        }

        $cookieName = $this->cookieName($request);
        $binding = $this->cookies->csrfBinding($request);
        $cookieToken = $request->cookie($cookieName);
        $hasValidCookie = $cookieToken !== null && $this->csrf->isValidToken($cookieToken, $binding);

        if (!$request->isMethodSafe()) {
            // The support entry exchange arrives as a cross-host POST from the
            // admin console. Its credential is a single-use, short-lived code,
            // so a forged request can only waste the attacker's own code.
            if (
                $request->method() === 'POST'
                && $request->hostGroup() === HostGroup::Tenant
                && $request->path() === '/support/enter'
            ) {
                return $next($request);
            }
            if (!$this->originAllowed($request) || $this->crossSiteNavigation($request)) {
                throw HttpException::csrfMismatch();
            }
            // Booking host: no sessions or ambient authority — writes are
            // authorised by rate limits and capability tokens (manage links,
            // holds). The embeddable widget runs in a cross-site iframe where
            // browsers withhold SameSite=Lax cookies, so a cookie-bound token
            // could never verify there. The Origin check above still rejects
            // cross-site form posts (a framed page's Origin is the booking
            // host itself, which is allowed).
            if ($group === HostGroup::Booking) {
                return $next($request);
            }
            $submitted = $request->input($this->config->string('security.csrf.field', '_csrf'));
            $submitted = is_string($submitted) ? $submitted : $request->header($this->config->string('security.csrf.header', 'X-CSRF-Token'));
            if (!$this->csrf->verify($cookieToken, $submitted, $binding)) {
                throw HttpException::csrfMismatch();
            }
        }

        // A token minted before login is rebound to the new session on the
        // first request after authentication.
        $token = $hasValidCookie ? $cookieToken : $this->csrf->issue($binding);
        $this->context->csrfToken = $token;

        $response = $next($request);

        if (!$hasValidCookie) {
            $response = $response->withCookie(new Cookie(
                name: $cookieName,
                value: $token,
                expiresAt: null,
                secure: $request->isSecure(),
                httpOnly: true,
                sameSite: 'Lax',
            ));
        }

        return $response;
    }

    private function cookieName(Request $request): string
    {
        // __Host- requires Secure, which local plain-HTTP development cannot satisfy.
        return $request->isSecure()
            ? $this->config->string('security.csrf.cookie_secure', '__Host-albabook_csrf')
            : $this->config->string('security.csrf.cookie_insecure', 'albabook_csrf');
    }

    /**
     * A present Origin must match the request's scheme and host; an absent
     * Origin falls back to the token check.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return true;
        }
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['host'])) {
            return false;
        }
        $scheme = $request->isSecure() ? 'https' : 'http';

        return strtolower((string) $parts['host']) === $request->host()
            && strtolower((string) ($parts['scheme'] ?? '')) === $scheme;
    }

    /** Browsers stamp Sec-Fetch-Site; a cross-site request is never our own UI. */
    private function crossSiteNavigation(Request $request): bool
    {
        return strtolower((string) $request->header('sec-fetch-site')) === 'cross-site';
    }
}
