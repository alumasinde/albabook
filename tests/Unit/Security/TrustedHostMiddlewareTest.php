<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Core\Http\HostGroup;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Middleware\TrustedHostMiddleware;
use App\Shared\Support\HostMap;
use App\Shared\Support\TrustedProxies;
use App\Tenancy\TenantDomainResolver;
use App\Tenancy\TenantRepository;
use PHPUnit\Framework\TestCase;

final class TrustedHostMiddlewareTest extends TestCase
{
    public function testKnownHostMapsToItsGroup(): void
    {
        $seen = $this->process(Request::create('GET', 'http://book.albabook.test:8080/'));

        self::assertSame(HostGroup::Booking, $seen->hostGroup());
        self::assertSame('book.albabook.test', $seen->host());
    }

    public function testHostIsCaseInsensitiveAndTrailingDotIsIgnored(): void
    {
        $seen = $this->process(Request::create('GET', 'http://app.albabook.test/', headers: ['Host' => 'APP.Albabook.test.']));

        self::assertSame(HostGroup::Tenant, $seen->hostGroup());
    }

    public function testUnknownHostIsRejectedBeforeRouting(): void
    {
        try {
            $this->process(Request::create('GET', 'http://evil.example.com/'));
            self::fail('Expected 400');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
            self::assertSame('UNKNOWN_HOST', $e->errorCode);
        }
    }

    public function testForwardedHostFromUntrustedPeerIsIgnored(): void
    {
        $request = Request::create('GET', 'http://evil.example.com/', headers: ['X-Forwarded-Host' => 'app.albabook.test'], server: ['REMOTE_ADDR' => '203.0.113.9']);

        $this->expectException(HttpException::class);
        $this->process($request);
    }

    public function testForwardedHeadersFromTrustedProxyAreUsed(): void
    {
        $request = Request::create('GET', 'http://internal-lb/', headers: [
            'X-Forwarded-Host' => 'admin.albabook.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '198.51.100.7, 10.0.0.5',
        ], server: ['REMOTE_ADDR' => '10.0.0.2']);

        $seen = $this->process($request);

        self::assertSame(HostGroup::Admin, $seen->hostGroup());
        self::assertTrue($seen->isSecure());
        self::assertSame('198.51.100.7', $seen->clientIp());
    }

    public function testSpoofedForwardedForFromUntrustedPeerDoesNotChangeClientIp(): void
    {
        $request = Request::create('GET', 'http://app.albabook.test/', headers: ['X-Forwarded-For' => '1.2.3.4'], server: ['REMOTE_ADDR' => '203.0.113.9']);

        self::assertSame('203.0.113.9', $this->process($request)->clientIp());
    }

    public function testIpv6LiteralHostIsRejected(): void
    {
        self::assertNull(TrustedHostMiddleware::normaliseHost('[::1]:8080'));
    }

    private function process(Request $request): Request
    {
        $middleware = new TrustedHostMiddleware(
            new HostMap(['app' => 'app.albabook.test', 'book' => 'book.albabook.test', 'api' => 'api.albabook.test', 'admin' => 'admin.albabook.test']),
            new TrustedProxies(['10.0.0.0/8']),
            new RequestContext(),
            // No rows can resolve in unit scope: the lazy Database is never
            // connected for platform hosts, and an unreachable lookup fails closed.
            new TenantRepository(new \App\Core\Database\Database([
                'host' => '127.0.0.1', 'port' => 1, 'database' => 'none',
                'username' => 'none', 'password' => '', 'charset' => 'utf8mb4',
            ])),
            new TenantDomainResolver(new \App\Core\Config\Config(['app' => ['hosts' => ['app.albabook.test', 'book.albabook.test', 'api.albabook.test', 'admin.albabook.test']]])),
        );
        $seen = null;
        $middleware->process($request, static function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::text('ok');
        });
        self::assertInstanceOf(Request::class, $seen);

        return $seen;
    }
}
