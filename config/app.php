<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'AlbaBook'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY', ''),

    // One host per interface (decisions.md D-005). Keys are App\Core\Http\HostGroup values.
    'hosts' => [
        'app' => env('APP_HOST_TENANT', 'app.albabook.co.ke'),
        'book' => env('APP_HOST_BOOKING', 'book.albabook.co.ke'),
        'api' => env('APP_HOST_API', 'api.albabook.co.ke'),
        'admin' => env('APP_HOST_ADMIN', 'admin.albabook.co.ke'),
    ],

    'default_tenant_timezone' => env('DEFAULT_TENANT_TIMEZONE', 'Africa/Nairobi'),
    'default_tenant_currency' => env('DEFAULT_TENANT_CURRENCY', 'KES'),

    'maintenance_file' => 'storage/framework/maintenance.json',
];
