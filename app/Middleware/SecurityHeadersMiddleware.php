<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config\Config;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Support\TokenGenerator;

/**
 * Baseline security headers on every response, error pages included.
 *
 * CSP: no inline script or style. A per-request nonce exists for the single
 * case that needs inline CSS later — tenant theme variables on the booking
 * page (plan.md §8.8) — and is exposed to Twig as csp_nonce().
 * A response that already set Content-Security-Policy (e.g. the future widget
 * route with its own frame-ancestors) keeps it.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly TokenGenerator $tokens,
        private readonly Config $config,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $nonce = $this->tokens->randomToken(16);
        $this->context->cspNonce = $nonce;

        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => self::contentSecurityPolicy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($this->config->isProduction() && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=' . $this->config->int('security.headers.hsts_max_age', 31536000) . '; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
