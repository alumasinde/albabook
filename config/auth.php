<?php

declare(strict_types=1);

/*
 * Authentication policy (auth.md §8b–8d). Values are validated at boot by
 * ConfigValidator; application code reads them through Config.
 */
return [
    'password' => [
        'min_length' => 12,
        'max_length' => 128,
        // Bundled top 10,000 list; compared case-insensitively.
        'common_passwords_path' => 'resources/security/common-passwords.txt',
    ],

    'tokens' => [
        'verification_hours' => 24,
        'password_reset_minutes' => 60,
        'invitation_days' => 7,
        'email_change_hours' => 24,
    ],

    'login' => [
        // Hard per-account lock: this many FAILED attempts in the window,
        // from any IP, locks the account until the window ends (even the
        // right password is refused). Stops distributed guessing; answers
        // 429 immediately instead of sleeping (LoginThrottle).
        'account_max_failures' => 10,
        'account_window_minutes' => 15,
        // Hard ceiling per IP (IPv6 grouped by /64) across all accounts.
        'ip_max_attempts' => 50,
        'ip_window_minutes' => 15,
        // Per-bucket ceilings for token-issuing endpoints.
        'reset_max_per_hour' => 5,
        // Sign-up: per IP (account farming) and per target email (bombing).
        'register_max_per_ip_per_hour' => 5,
        'register_max_per_email_per_hour' => 3,
        'verification_resend_max_per_hour' => 5,
    ],

    'mfa' => [
        'issuer' => env('APP_NAME', 'AlbaBook'),
        'digits' => 6,
        'period' => 30,
        // ±1 step (auth.md §8c).
        'leeway_steps' => 1,
        'recovery_codes' => 10,
        'step_up_minutes' => 10,
    ],
];
