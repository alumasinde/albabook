<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\HostGroup;
use App\Core\Middleware\MiddlewareInterface;

final readonly class Route
{
    /**
     * @param list<string>                                 $methods
     * @param array{0: class-string, 1: string}            $handler
     * @param list<class-string<MiddlewareInterface>>       $middleware route-specific, run after the group stack
     */
    public function __construct(
        public HostGroup $group,
        public array $methods,
        public string $path,
        public array $handler,
        public array $middleware = [],
        public ?string $name = null,
    ) {
    }
}
