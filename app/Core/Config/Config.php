<?php

declare(strict_types=1);

namespace App\Core\Config;

/**
 * Read-only configuration loaded once from config/*.php.
 * Keys use dot notation: "app.hosts.book".
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items) {}

    public static function loadFrom(string $directory): self
    {
        $items = [];
        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            $values = require $file;
            if (is_array($values)) {
                $items[basename($file, '.php')] = $values;
            }
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<mixed> */
    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function isProduction(): bool
    {
        return $this->string('app.env') === 'production';
    }

    public function isDebug(): bool
    {
        return $this->bool('app.debug') && !$this->isProduction();
    }

    /** Decoded 32-byte application key. Only call after validation. */
    public function appKey(): string
    {
        return ConfigValidator::decodeKey($this->string('app.key')) ?? '';
    }
}
