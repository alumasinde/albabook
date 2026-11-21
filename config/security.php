<?php

declare(strict_types=1);

$trusted = (string) env('TRUSTED_PROXIES', '');

return [
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', $trusted)))),

    'secure_cookies' => env('SESSION_SECURE_COOKIE', true),

    // Server-side sessions (auth.md §8a). __Host- names require Secure;
    // plain names are used on local HTTP only.
    'session' => [
        'idle_timeout_minutes' => 120,
        'absolute_timeout_minutes' => 720,
        'touch_interval_seconds' => 60,
        'cookie_secure' => '__Host-albabook_app',
        'cookie_insecure' => 'albabook_app',
    ],

    'remember' => [
        'lifetime_days' => 30,
        'cookie_secure' => '__Host-albabook_remember',
        'cookie_insecure' => 'albabook_remember',
    ],

    // Signed entry token for audited support sessions on the tenant host.
    'support' => [
        'cookie_secure' => '__Host-albabook_support',
        'cookie_insecure' => 'albabook_support',
    ],

    // Platform administrators have a separate cookie and optional IP allowlist.
    'platform' => [
        'cookie_secure' => '__Host-albabook_admin',
        'cookie_insecure' => 'albabook_admin',
        'ip_allowlist' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SECURITY_PLATFORM_IP_ALLOWLIST', '')),
        ))),
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
