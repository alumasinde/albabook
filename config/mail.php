<?php

declare(strict_types=1);

/*
 * Transactional mail. `log` writes to storage/logs/mail.log and is refused
 * outside local/testing; `smtp` talks to a real submission server.
 */
return [
    'transport' => env('MAIL_TRANSPORT', env('MAIL_MAILER', 'log') ?: 'log') ?: 'log',
    'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@albabook.co.ke') ?: 'no-reply@albabook.co.ke',
    'from_name' => env('MAIL_FROM_NAME', 'AlbaBook') ?: 'AlbaBook',
    'log_path' => 'storage/logs/mail.log',
    'smtp' => [
        'host' => env('MAIL_HOST', '') ?: '',
        'port' => (int) (env('MAIL_PORT', '587') ?: '587'),
        'encryption' => env('MAIL_ENCRYPTION', 'tls') ?: 'tls',
        'username' => env('MAIL_USERNAME', '') ?: null,
        'password' => env('MAIL_PASSWORD', '') ?: null,
    ],
];
