<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Public hosts (marketing site, booking pages) serve either a full document
 * or an SPA fragment for the same URL, so `Vary: X-SPA` keeps a CDN or the
 * browser cache from replaying one as the other. Pages embed a per-visitor
 * CSRF token, so they are revalidated (`no-cache`) rather than shared.
 * A controller that already set Cache-Control (e.g. /health) keeps its own.
 */
final class PublicPageCacheMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request)->withHeader('Vary', 'X-SPA');

        return $response->header('Cache-Control') === null
            ? $response->withHeader('Cache-Control', 'no-cache')
            : $response;
    }
}
