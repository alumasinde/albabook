<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Modules\Auth\Context\CurrentMembership;

/**
 * Declarative permission gate: route option `permission` must be held by the
 * current membership. Always runs after TenantMiddleware.
 */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CurrentMembership $membership) {}

    public function process(Request $request, callable $next): Response
    {
        $options = $request->attribute('route_options', []);
        $permission = is_array($options) ? ($options['permission'] ?? null) : null;

        if (!is_string($permission) || $permission === '') {
            return $next($request);
        }

        $membership = $this->membership->get();
        if ($membership === null || !$membership->can($permission)) {
            throw new HttpException(403, 'FORBIDDEN', 'You do not have permission to do that.');
        }

        return $next($request);
    }
}
