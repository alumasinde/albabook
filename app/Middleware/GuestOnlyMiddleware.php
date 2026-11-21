<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Auth\Context\AuthContext;

/**
 * Login, registration and password-reset pages are for signed-out visitors.
 * A half-authenticated session (MFA outstanding) is sent to verification.
 */
final class GuestOnlyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthContext $auth) {}

    public function process(Request $request, callable $next): Response
    {
        if ($this->auth->user !== null) {
            return Response::redirect($this->auth->mfaPending ? '/two-factor' : '/');
        }

        return $next($request);
    }
}
