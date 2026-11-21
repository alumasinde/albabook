<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config\Config;
use App\Core\Http\Cookie;
use App\Core\Http\HostGroup;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Chooses the display language (decisions.md D-017).
 *
 * - Booking host: ?lang= (remembered in a cookie) → cookie → Accept-Language,
 *   limited to customer-facing locales.
 * - Every other host: dashboard locales only (English at launch).
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly Config $config,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $fallback = $this->config->string('i18n.fallback_locale', 'en');
        $customerFacing = $request->hostGroup() === HostGroup::Booking;
        /** @var list<string> $allowed */
        $allowed = $this->config->array($customerFacing ? 'i18n.customer_locales' : 'i18n.dashboard_locales');
        $cookieName = $this->config->string('i18n.cookie', 'albabook_lang');

        $requested = $customerFacing ? $request->query('lang') : null;
        $requested = is_string($requested) && in_array($requested, $allowed, true) ? $requested : null;

        $locale = $requested
            ?? $this->fromCookie($request, $cookieName, $allowed)
            ?? ($customerFacing ? $this->fromAcceptLanguage($request, $allowed) : null)
            ?? $fallback;

        $this->context->locale = $locale;
        $response = $next($request->withAttribute('locale', $locale));

        if ($requested !== null) {
            $response = $response->withCookie(new Cookie(
                name: $cookieName,
                value: $requested,
                expiresAt: time() + 365 * 86400,
                secure: $request->isSecure(),
                httpOnly: true,
            ));
        }

        return $response;
    }

    /** @param list<string> $allowed */
    private function fromCookie(Request $request, string $name, array $allowed): ?string
    {
        $value = $request->cookie($name);

        return $value !== null && in_array($value, $allowed, true) ? $value : null;
    }

    /** @param list<string> $allowed */
    private function fromAcceptLanguage(Request $request, array $allowed): ?string
    {
        $header = (string) $request->header('accept-language', '');
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($tag, $allowed, true)) {
                return $tag;
            }
        }

        return null;
    }
}
