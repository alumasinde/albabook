<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\ExceptionHandler;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Sits inside RequestId and SecurityHeaders so error responses still carry the
 * request id and the security headers.
 */
final class ErrorHandlingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ExceptionHandler $handler) {}

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $e) {
            return $this->handler->render($e, $request);
        }
    }
}
