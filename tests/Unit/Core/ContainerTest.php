<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Container;
use App\Core\ContainerException;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testRegisteredFactoriesAreSharedByDefault(): void
    {
        $c = new Container();
        $c->set(\stdClass::class, static fn (): \stdClass => new \stdClass());

        self::assertSame($c->get(\stdClass::class), $c->get(\stdClass::class));
    }

    public function testNonSharedFactoriesReturnNewInstances(): void
    {
        $c = new Container();
        $c->set('thing', static fn (): \stdClass => new \stdClass(), shared: false);

        self::assertNotSame($c->get('thing'), $c->get('thing'));
    }

    public function testAutowiresConstructorDependencies(): void
    {
        $c = new Container();
        $service = $c->get(ContainerFixtureService::class);

        self::assertInstanceOf(ContainerFixtureDependency::class, $service->dependency);
        self::assertSame(5, $service->retries);
    }

    public function testAutowiredClassesAreNotShared(): void
    {
        $c = new Container();
        self::assertNotSame($c->get(ContainerFixtureDependency::class), $c->get(ContainerFixtureDependency::class));
    }

    public function testUnresolvableScalarFailsWithHelpfulMessage(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');
        (new Container())->get(ContainerFixtureNeedsScalar::class);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $c = new Container();
        $c->set('a', static fn (Container $c): mixed => $c->get('b'));
        $c->set('b', static fn (Container $c): mixed => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular');
        $c->get('a');
    }
}

final class ContainerFixtureDependency
{
}

final class ContainerFixtureService
{
    public function __construct(public readonly ContainerFixtureDependency $dependency, public readonly int $retries = 5)
    {
    }
}

final class ContainerFixtureNeedsScalar
{
    public function __construct(public readonly string $name)
    {
    }
}
