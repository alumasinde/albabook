<?php

declare(strict_types=1);

// Mirrors the `locales` lookup table (000_reference_data.sql, decisions.md D-017).
// A test asserts the two agree.
return [
    'fallback_locale' => 'en',
    'path' => 'lang',
    'dashboard_locales' => ['en'],
    'customer_locales' => ['en', 'sw'],
    'cookie' => 'albabook_lang',
];
