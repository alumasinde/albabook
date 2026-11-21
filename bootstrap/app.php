<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config\Config;
use App\Core\Config\ConfigValidator;
use App\Core\Container;
use App\Core\Middleware\Pipeline;
use App\Core\Routing\Router;
use Dotenv\Dotenv;

/**
 * Builds the application. Returns a ready Application or throws
 * ConfigurationException listing every configuration problem.
 *
 * $overrides lets tests inject services (e.g. a FrozenClock) before boot.
 *
 * @param array<string, mixed> $overrides service id => instance
 */
return static function (array $overrides = []): Application {
    $basePath = dirname(__DIR__);

    require_once $basePath . '/vendor/autoload.php';

    date_default_timezone_set('UTC');

    // Real environment variables win over .env (immutable), so containers and
    // phpunit.xml can configure the app without a file.
    Dotenv::createImmutable($basePath)->safeLoad();

    $config = Config::loadFrom($basePath . '/config');
    (new ConfigValidator())->assertValid($config);

    $container = new Container();
    $container->instance('base_path', $basePath);
    $container->instance(Config::class, $config);
    $container->instance(Container::class, $container);

    (require __DIR__ . '/container.php')($container, $config, $basePath);

    foreach ($overrides as $id => $instance) {
        $container->instance($id, $instance);
    }

    $router = new Router();
    (require __DIR__ . '/routes.php')($router, $basePath);
    $container->instance(Router::class, $router);

    /** @var array{global: list<class-string<App\Core\Middleware\MiddlewareInterface>>, groups: array<string, list<class-string<App\Core\Middleware\MiddlewareInterface>>>} $middleware */
    $middleware = require __DIR__ . '/middleware.php';

    $app = new Application(
        $container,
        $router,
        new Pipeline($container),
        $middleware['global'],
        $middleware['groups'],
    );
    $container->instance(Application::class, $app);

    return $app;
};
