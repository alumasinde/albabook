<?php

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Read an environment variable with light type casting.
     *
     * Only config/*.php files may call this. Application code reads
     * configuration through App\Core\Config so values are validated once.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
