<?php

declare(strict_types=1);

/*
 * Transactional mail. Phase 2 ships only the log transport: it writes the
 * message to storage/logs/mail.log and is refused outside local/testing so
 * verification and reset links are never silently discarded.
 */
return [
    'transport' => env('MAIL_TRANSPORT', 'log') ?: 'log',
    'from_address' => env('MAIL_FROM_ADDRESS', 'no-reply@albabook.co.ke') ?: 'no-reply@albabook.co.ke',
    'from_name' => env('MAIL_FROM_NAME', 'AlbaBook') ?: 'AlbaBook',
    'log_path' => 'storage/logs/mail.log',
];
