<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\Http\Cookie;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Middleware\MiddlewareInterface;
use App\Core\Middleware\Pipeline;
use PHPUnit\Framework\TestCase;

final class PipelineAndHttpTest extends TestCase
{
    public function testMiddlewareRunsOutermostFirstAndUnwindsInReverse(): void
    {
        $log = new \ArrayObject();
        $pipeline = new Pipeline(new Container());

        $response = $pipeline->run(
            Request::create('GET', 'http://app.albabook.test/'),
            [new TraceMiddleware('outer', $log), new TraceMiddleware('inner', $log)],
            static function () use ($log): Response {
                $log[] = 'controller';

                return Response::text('ok');
            },
        );

        self::assertSame(['outer:before', 'inner:before', 'controller', 'inner:after', 'outer:after'], $log->getArrayCopy());
        self::assertSame('outer', $response->header('X-Trace'));
    }

    public function testRequestCreateNormalisesPathAndHeaders(): void
    {
        $request = Request::create('post', 'https://Book.Albabook.test/royal-beauty/?lang=sw', ['a' => 'b'], ['X-Custom' => 'v']);

        self::assertSame('POST', $request->method());
        self::assertSame('/royal-beauty', $request->path());
        self::assertSame('sw', $request->query('lang'));
        self::assertSame('v', $request->header('x-custom'));
        self::assertSame('b', $request->input('a'));
        self::assertTrue($request->isSecure());
    }

    public function testWithAttributeIsImmutable(): void
    {
        $request = Request::create('GET', 'http://app.albabook.test/');
        $changed = $request->withAttribute('x', 1);

        self::assertNull($request->attribute('x'));
        self::assertSame(1, $changed->attribute('x'));
    }

    public function testHostPrefixedCookieMustBeSecure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('__Host-test', 'v', secure: false);
    }

    public function testCookieHeaderHasNoDomainAttribute(): void
    {
        $header = (new Cookie('__Host-albabook_csrf', 'abc', secure: true))->toHeaderValue();

        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringNotContainsStringIgnoringCase('Domain=', $header);
    }

    public function testCookieRejectsUnsafeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cookie('name', "value;injected=1");
    }

    public function testResponseHeaderNamesAreCaseInsensitive(): void
    {
        $response = (new Response('x'))->withHeader('x-request-id', 'abc');

        self::assertSame('abc', $response->header('X-REQUEST-ID'));
        self::assertTrue($response->hasHeader('X-Request-Id'));
    }
}

final class TraceMiddleware implements MiddlewareInterface
{
    /** @param \ArrayObject<int, string> $log */
    public function __construct(private readonly string $name, private readonly \ArrayObject $log)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->log[] = "{$this->name}:before";
        $response = $next($request);
        $this->log[] = "{$this->name}:after";

        return $response->withHeader('X-Trace', $this->name);
    }
}
