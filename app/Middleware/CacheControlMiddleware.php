<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Authenticated hosts (app + admin) must never be stored by browser or shared
 * caches: responses carry customer data, receipts and session-bound state.
 * `Vary: X-SPA` is emitted because SpaFragmentMiddleware serves either a full
 * document or a fragment depending on that header — without Vary a shared
 * cache could replay a fragment as a full page or vice-versa.
 */
final class CacheControlMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('Vary', 'X-SPA');
    }
}
