<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Routing\RouteRegistrar;
use App\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testRoutesAreIsolatedPerHostGroup(): void
    {
        $router = new Router();
        $router->group(HostGroup::Tenant, static function (RouteRegistrar $r): void {
            $r->get('/dashboard', [self::class, 'handler']);
        });

        self::assertSame('/dashboard', $router->match(HostGroup::Tenant, 'GET', '/dashboard')->route->path);

        try {
            $router->match(HostGroup::Booking, 'GET', '/dashboard');
            self::fail('A tenant route must not be reachable on the booking host.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testEveryHostRoutesExistOnAllGroups(): void
    {
        $router = new Router();
        $router->everyHost(static function (RouteRegistrar $r): void {
            $r->get('/health', [self::class, 'handler']);
        });

        foreach (HostGroup::cases() as $group) {
            self::assertSame($group, $router->match($group, 'GET', '/health')->route->group);
        }
    }

    public function testParametersAreExtracted(): void
    {
        $router = new Router();
        $router->group(HostGroup::Booking, static function (RouteRegistrar $r): void {
            $r->get('/{slug:[a-z0-9-]+}/manage/{token}', [self::class, 'handler']);
        });

        $match = $router->match(HostGroup::Booking, 'GET', '/royal-beauty/manage/abc123');
        self::assertSame(['slug' => 'royal-beauty', 'token' => 'abc123'], $match->parameters);
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $router = new Router();
        $router->group(HostGroup::Tenant, static function (RouteRegistrar $r): void {
            $r->post('/appointments', [self::class, 'handler']);
        });

        try {
            $router->match(HostGroup::Tenant, 'DELETE', '/appointments');
            self::fail('Expected 405');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
            self::assertSame('POST', $e->headers['Allow']);
        }
    }

    public function testHeadIsAnsweredByGetRoutes(): void
    {
        $router = new Router();
        $router->group(HostGroup::Tenant, static function (RouteRegistrar $r): void {
            $r->get('/', [self::class, 'handler']);
        });

        self::assertSame('/', $router->match(HostGroup::Tenant, 'HEAD', '/')->route->path);
    }

    public function testRoutesAddedLaterAreMatched(): void
    {
        $router = new Router();
        $router->group(HostGroup::Api, static fn (RouteRegistrar $r) => $r->get('/a', [self::class, 'handler']));
        $router->match(HostGroup::Api, 'GET', '/a');
        $router->group(HostGroup::Api, static fn (RouteRegistrar $r) => $r->get('/b', [self::class, 'handler']));

        self::assertSame('/b', $router->match(HostGroup::Api, 'GET', '/b')->route->path);
    }

    public static function handler(): void
    {
    }
}
