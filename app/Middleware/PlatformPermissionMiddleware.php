<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Platform\Context\PlatformAuthContext;

/**
 * Declarative platform-permission gate: route option `platform_permission`
 * must be held by the signed-in administrator. Platform permissions never
 * come from tenant roles (auth.md §10).
 */
final class PlatformPermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly PlatformAuthContext $auth) {}

    public function process(Request $request, callable $next): Response
    {
        $options = $request->attribute('route_options', []);
        $permission = is_array($options) ? ($options['platform_permission'] ?? null) : null;

        if (!is_string($permission) || $permission === '') {
            return $next($request);
        }

        if (!$this->auth->can($permission)) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to do that.');
        }

        return $next($request);
    }
}
