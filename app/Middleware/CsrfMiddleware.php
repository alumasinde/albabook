<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config\Config;
use App\Core\Http\Cookie;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Security\CsrfService;

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
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $group = $request->hostGroup();
        if ($group === null || !$group->usesCsrf()) {
            return $next($request);
        }

        $cookieName = $this->cookieName($request);
        $cookieToken = $request->cookie($cookieName);
        $hasValidCookie = $cookieToken !== null && $this->csrf->isValidToken($cookieToken);

        if (!$request->isMethodSafe()) {
            if (!$this->originAllowed($request)) {
                throw HttpException::csrfMismatch();
            }
            $submitted = $request->input($this->config->string('security.csrf.field', '_csrf'));
            $submitted = is_string($submitted) ? $submitted : $request->header($this->config->string('security.csrf.header', 'X-CSRF-Token'));
            if (!$this->csrf->verify($cookieToken, $submitted)) {
                throw HttpException::csrfMismatch();
            }
        }

        $token = $hasValidCookie ? $cookieToken : $this->csrf->issue();
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

    /** A present Origin must be this exact host; an absent Origin falls back to the token check. */
    private function originAllowed(Request $request): bool
    {
        $origin = $request->header('origin');
        if ($origin === null || $origin === '') {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);

        return is_string($host) && strtolower($host) === $request->host();
    }
}
