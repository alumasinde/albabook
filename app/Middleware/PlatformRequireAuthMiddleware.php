<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Platform\Context\PlatformAuthContext;

/**
 * Route middleware for the admin host. MFA is mandatory for every platform
 * administrator (auth.md §10), so an unverified session can only reach the
 * enrolment or verification pages.
 */
final class PlatformRequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PlatformAuthContext $auth) {}

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'UNAUTHENTICATED', 'Sign in to continue.');
            }

            return Response::redirect('/login');
        }

        if ($this->auth->mfaPending) {
            return Response::redirect($user->mfaEnrolled ? '/two-factor' : '/mfa/enroll');
        }

        return $next($request);
    }
}
