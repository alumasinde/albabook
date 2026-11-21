<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small dependency-injection container.
 *
 * - Registered factories are shared (one instance per container) unless
 *   registered with shared = false.
 * - Unregistered concrete classes are autowired from constructor type hints
 *   and are NOT shared, so controllers and handlers start fresh.
 * - Background workers will create a child scope per job (Phase 8) so
 *   tenant-aware services never leak between jobs (multi-tenancy.md).
 */
final class Container
{
    /** @var array<string, array{factory: callable(self): mixed, shared: bool}> */
    private array $definitions = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @param callable(self): mixed $factory */
    public function set(string $id, callable $factory, bool $shared = true): void
    {
        $this->definitions[$id] = ['factory' => $factory, 'shared' => $shared];
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->definitions[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerException("Circular dependency while resolving {$id}.");
        }

        $this->resolving[$id] = true;
        try {
            if (isset($this->definitions[$id])) {
                $definition = $this->definitions[$id];
                $value = ($definition['factory'])($this);
                if ($definition['shared']) {
                    $this->instances[$id] = $value;
                }

                return $value;
            }

            return $this->autowire($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    private function autowire(string $class): object
    {
        if (!class_exists($class)) {
            throw new ContainerException("No definition or class found for {$class}.");
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("{$class} is not instantiable; register a factory for it.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new ContainerException(
                "Cannot autowire parameter \${$parameter->getName()} of {$class}; register a factory."
            );
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
