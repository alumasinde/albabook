<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Platform\Context\PlatformAuthContext;

final class PlatformGuestOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PlatformAuthContext $auth) {}

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user;
        if ($user === null) {
            return $next($request);
        }

        if ($this->auth->mfaPending) {
            return Response::redirect($user->mfaEnrolled ? '/two-factor' : '/mfa/enroll');
        }

        return Response::redirect('/dashboard');
    }
}
