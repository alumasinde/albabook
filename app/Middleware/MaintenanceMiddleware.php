<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Support\MaintenanceMode;

/**
 * Returns 503 with Retry-After while maintenance mode is on
 * (php bin/console down / up). /health stays reachable so load balancers and
 * monitoring can see the state.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MaintenanceMode $maintenance)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($request->path() !== '/health' && $this->maintenance->isActive()) {
            throw HttpException::serviceUnavailable($this->maintenance->retryAfterSeconds());
        }

        return $next($request);
    }
}
