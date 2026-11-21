<?php

declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', ''),
    'username' => env('DB_USERNAME', ''),
    'password' => (string) env('DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',

    // Applied on every connection (booking-engine.md §3, plan.md §13).
    'sql_mode' => implode(',', [
        'ONLY_FULL_GROUP_BY',
        'STRICT_ALL_TABLES',
        'NO_ZERO_IN_DATE',
        'NO_ZERO_DATE',
        'ERROR_FOR_DIVISION_BY_ZERO',
        'NO_ENGINE_SUBSTITUTION',
    ]),

    'migrations_path' => 'database/migrations',

    // Booking-write transactions (decisions.md D-009).
    'transaction' => [
        'max_attempts' => 3,
        'retry_base_delay_ms' => 25,
    ],
];
