<?php

declare(strict_types=1);

/*
 * Platform-owned fallback channel accounts (D-014, hybrid model): when a
 * tenant has no account of their own for a channel, messages send through
 * the platform's account with the shared sender identity. A tenant's own
 * configured account always wins. Empty provider = fallback disabled.
 */
return [
    'platform' => [
        'sms' => [
            'provider' => env('SMS_PROVIDER', '') ?: '',
            'credentials' => [
                'username' => env('SMS_USERNAME', '') ?: '',
                'api_key' => env('SMS_API_KEY', '') ?: '',
            ],
            'sender_id' => env('SMS_SENDER_ID', 'AlbaBook') ?: 'AlbaBook',
        ],
        'whatsapp' => [
            'provider' => env('WHATSAPP_PROVIDER', '') ?: '',
            'credentials' => [
                'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID', '') ?: '',
                'access_token' => env('WHATSAPP_ACCESS_TOKEN', '') ?: '',
            ],
            'sender_id' => env('WHATSAPP_SENDER_ID', '') ?: '',
        ],
    ],
];
