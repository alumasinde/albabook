<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Auth\Context\AuthContext;

/**
 * Route middleware for authenticated tenant-user pages.
 */
final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthContext $auth) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->user === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
            }

            return Response::redirect('/login' . $this->nextParam($request));
        }

        if ($this->auth->mfaPending) {
            return Response::redirect('/two-factor' . $this->nextParam($request));
        }

        return $next($request);
    }

    /**
     * Only a local path is remembered, never a full URL, so `next` cannot be
     * turned into an open redirect.
     */
    private function nextParam(Request $request): string
    {
        if (!$request->isMethodSafe() || $request->path() === '/') {
            return '';
        }

        $query = $request->queryAll();
        $target = $request->path() . ($query === [] ? '' : '?' . http_build_query($query));

        return '?next=' . urlencode($target);
    }
}
