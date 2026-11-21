<?php

declare(strict_types=1);

namespace App\Core\Middleware;

use App\Core\Container;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Runs middleware in the order given; the first entry is the outermost layer.
 */
final class Pipeline
{
    public function __construct(private readonly Container $container) {}

    /**
     * @param list<class-string<MiddlewareInterface>|MiddlewareInterface> $middleware
     * @param callable(Request): Response                                 $destination
     */
    public function run(Request $request, array $middleware, callable $destination): Response
    {
        $next = $destination;
        foreach (array_reverse($middleware) as $layer) {
            $instance = $layer instanceof MiddlewareInterface ? $layer : $this->container->get($layer);
            if (!$instance instanceof MiddlewareInterface) {
                throw new \LogicException(sprintf('%s is not a middleware.', get_debug_type($instance)));
            }
            $current = $next;
            $next = static fn(Request $request): Response => $instance->process($request, $current);
        }

        return $next($request);
    }
}
