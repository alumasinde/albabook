<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Marks safe requests that ask for an SPA fragment (X-SPA: fragment). Shells
 * then render only the page content block; the client swaps it into the live
 * document and manages history itself. Full-page responses are unaffected.
 */
final class SpaFragmentMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly RequestContext $context) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->isMethodSafe() && $request->header('x-spa') === 'fragment') {
            $this->context->isSpa = true;
        }

        return $next($request);
    }
}
