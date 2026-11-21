<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\HostGroup;
use App\Core\Middleware\MiddlewareInterface;

/**
 * Fluent route definition used by routes/*.php.
 * Handlers are [ControllerClass::class, 'method'] and receive
 * (Request $request, array $params): Response.
 */
final class RouteRegistrar
{
    /** @param list<HostGroup> $groups */
    public function __construct(
        private readonly Router $router,
        private readonly array $groups,
    ) {
    }

    /**
     * @param array{0: class-string, 1: string}      $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function get(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->map(['GET'], $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0: class-string, 1: string}      $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function post(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->map(['POST'], $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0: class-string, 1: string}      $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function patch(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->map(['PATCH'], $path, $handler, $middleware, $name);
    }

    /**
     * @param array{0: class-string, 1: string}      $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function delete(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->map(['DELETE'], $path, $handler, $middleware, $name);
    }

    /**
     * @param list<string>                            $methods
     * @param array{0: class-string, 1: string}      $handler
     * @param list<class-string<MiddlewareInterface>> $middleware
     */
    public function map(array $methods, string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        foreach ($this->groups as $group) {
            $this->router->add(new Route($group, $methods, $path, $handler, $middleware, $name));
        }
    }
}
