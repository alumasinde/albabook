<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

/**
 * Host-aware router. Each HostGroup has an independent route table, so a path
 * defined for the tenant portal can never be reached on the booking host.
 */
final class Router
{
    /** @var array<string, list<Route>> */
    private array $routes = [];

    /** @var array<string, Dispatcher> */
    private array $dispatchers = [];

    /** @param callable(RouteRegistrar): void $define */
    public function group(HostGroup $group, callable $define): void
    {
        $define(new RouteRegistrar($this, [$group]));
    }

    /**
     * Routes that exist on every host (health checks, the component catalogue).
     *
     * @param callable(RouteRegistrar): void $define
     */
    public function everyHost(callable $define): void
    {
        $define(new RouteRegistrar($this, HostGroup::cases()));
    }

    public function add(Route $route): void
    {
        $this->routes[$route->group->value][] = $route;
        unset($this->dispatchers[$route->group->value]);
    }

    /** @throws HttpException 404 or 405 */
    public function match(HostGroup $group, string $method, string $path): RouteMatch
    {
        // HEAD is answered by GET routes; the body is dropped when sending.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;
        $result = $this->dispatcher($group)->dispatch($lookupMethod, $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch($result[1], array_map('strval', $result[2])),
            Dispatcher::METHOD_NOT_ALLOWED => throw HttpException::methodNotAllowed($result[1]),
            default => throw HttpException::notFound(),
        };
    }

    /** @return list<Route> */
    public function routesFor(HostGroup $group): array
    {
        return $this->routes[$group->value] ?? [];
    }

    private function dispatcher(HostGroup $group): Dispatcher
    {
        return $this->dispatchers[$group->value] ??= simpleDispatcher(
            function (RouteCollector $collector) use ($group): void {
                foreach ($this->routes[$group->value] ?? [] as $route) {
                    $collector->addRoute($route->methods, $route->path, $route);
                }
            }
        );
    }
}
