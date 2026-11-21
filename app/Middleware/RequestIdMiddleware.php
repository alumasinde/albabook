<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Shared\Support\UuidFactory;

/**
 * Outermost layer. Gives every request an id that appears in every log line,
 * the X-Request-Id response header and user-facing error pages.
 * An incoming id is reused only when it is a short, safe token, so it cannot
 * inject content into logs.
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly UuidFactory $uuids,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $incoming = $request->header('x-request-id');
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1
            ? $incoming
            : $this->uuids->generate();

        $this->context->requestId = $id;

        return $next($request->withAttribute('request_id', $id))->withHeader('X-Request-Id', $id);
    }
}
