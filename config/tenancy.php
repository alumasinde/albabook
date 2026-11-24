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
        '_', 'admin', 'api', 'app', 'assets', 'availability', 'book',
        'embed', 'help', 'login', 'manage', 'staff', 'static', 'support',
        'widget', 'www',
    ], array_filter(array_map('trim', explode(',', (string) env('TENANCY_RESERVED_SLUGS', ''))))))),

    // Tenant custom booking domains (plan.md §24). OFF until the edge can
    // serve them: nginx must route unknown hosts to PHP and TLS certificates
    // must be issued per domain (docs/deployment.md). While off, custom
    // hosts never resolve and the dashboard cannot add or activate domains.
    // Raw env value: Config::bool() parses "true"/"false"/"1"/"0" correctly.
    'custom_domains_enabled' => env('CUSTOM_DOMAINS_ENABLED', false),

    // An unverified domain claim lapses after this many hours, so one tenant
    // cannot park a domain another business actually owns.
    'domain_claim_ttl_hours' => 72,

    // Old slugs keep redirecting and stay unclaimable (multi-tenancy.md §4).
    'history_redirect_months' => 12,

    'support' => [
        'default_minutes' => 60,
        'max_minutes' => 240,
    ],
];
