<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\Pipeline;
use App\Core\Routing\Router;
use Psr\Log\LoggerInterface;

/**
 * HTTP kernel: global middleware → route match → group + route middleware → controller.
 */
final class Application
{
    /**
     * @param list<class-string<MiddlewareInterface>>               $globalMiddleware outermost first
     * @param array<string, list<class-string<MiddlewareInterface>>> $groupMiddleware  keyed by HostGroup value
     */
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Pipeline $pipeline,
        private readonly array $globalMiddleware,
        private readonly array $groupMiddleware,
    ) {
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->pipeline->run($request, $this->globalMiddleware, fn (Request $r): Response => $this->dispatch($r));
        } catch (\Throwable $e) {
            // Last resort: ErrorHandlingMiddleware normally renders errors. Reaching
            // here means the error handling itself failed, so stay minimal.
            try {
                $this->container->get(LoggerInterface::class)->critical('Unhandled error outside error middleware', ['exception' => $e]);
            } catch (\Throwable) {
                error_log('AlbaBook: unhandled error: ' . $e::class);
            }

            return Response::text('Something went wrong.', 500);
        }
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request);
        $response->send($request->method() !== 'HEAD');
    }

    private function dispatch(Request $request): Response
    {
        $group = $request->hostGroup();
        if ($group === null) {
            throw HttpException::badRequest('UNKNOWN_HOST', 'Unknown host.');
        }

        $match = $this->router->match($group, $request->method(), $request->path());
        $request = $request->withAttribute('route', $match->route)->withAttribute('route_params', $match->parameters);

        $middleware = array_merge($this->groupMiddleware[$group->value] ?? [], $match->route->middleware);

        return $this->pipeline->run($request, $middleware, function (Request $request) use ($match): Response {
            [$class, $method] = $match->route->handler;
            $controller = $this->container->get($class);
            $response = $controller->{$method}($request, $match->parameters);
            if (!$response instanceof Response) {
                throw new \LogicException("{$class}::{$method} must return a Response.");
            }

            return $response;
        });
    }
}
