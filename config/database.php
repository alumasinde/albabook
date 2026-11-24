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

    // Seconds to wait for the TCP connection. A refused connect fails fast,
    // but a dropped/firewalled one would otherwise block a request for
    // default_socket_timeout (60s).
    'connect_timeout' => (int) env('DB_CONNECT_TIMEOUT', 5),

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

    // Least privilege (production): the web app connects as DB_USERNAME,
    // which only needs SELECT/INSERT/UPDATE/DELETE. Schema changes run from
    // the CLI (php bin/console migrate) as this separate schema-owner user.
    // Empty = migrations use DB_USERNAME (local development).
    'migration_username' => (string) env('DB_MIGRATE_USERNAME', ''),
    'migration_password' => (string) env('DB_MIGRATE_PASSWORD', ''),

    // Booking-write transactions (decisions.md D-009).
    'transaction' => [
        'max_attempts' => 3,
        'retry_base_delay_ms' => 25,
    ],
];
