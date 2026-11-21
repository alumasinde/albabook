<?php

declare(strict_types=1);

$trusted = (string) env('TRUSTED_PROXIES', '');

return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $trusted)))),

    'secure_cookies' => env('SESSION_SECURE_COOKIE', true),

    // Sessions arrive in Phase 2 (auth.md §8a); kept here so Phase 2 only fills them in.
    'session' => [
        'idle_timeout_minutes' => 120,
        'absolute_timeout_minutes' => 720,
    ],

    'csrf' => [
        'field' => '_csrf',
        'header' => 'X-CSRF-Token',
        // __Host- prefix requires Secure; the plain name is used for local HTTP.
        'cookie_secure' => '__Host-albabook_csrf',
        'cookie_insecure' => 'albabook_csrf',
        // Host groups whose state-changing requests are exempt (token-authenticated).
        'exempt_groups' => ['api'],
    ],

    'headers' => [
        'hsts_max_age' => 31536000,
    ],
];
