<?php

declare(strict_types=1);

/*
 * Tenant resolution rules
 */
return [
    'slug' => [
        'min_length' => 3,
        'max_length' => 60,
        'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/',
    ],

    // Rejected at registration and rename. The platform-managed additions can
    // be supplied through TENANCY_RESERVED_SLUGS (comma separated).
    'reserved_slugs' => array_values(array_unique(array_merge([
        '_', 'admin', 'api', 'app', 'assets', 'book', 'help', 'login',
        'manage', 'static', 'support', 'widget', 'www',
    ], array_filter(array_map('trim', explode(',', (string) env('TENANCY_RESERVED_SLUGS', ''))))))),

    // Old slugs keep redirecting and stay unclaimable (multi-tenancy.md §4).
    'history_redirect_months' => 12,

    'support' => [
        'default_minutes' => 60,
        'max_minutes' => 240,
    ],
];
