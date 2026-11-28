<?php

declare(strict_types=1);

/*
 * Payment integrations.
 */
return [
    'mpesa' => [
        // Public base URL Safaricom posts STK results to. Empty = the API
        // host (https://api.albabook.co.ke). Local development needs a public
        // tunnel (e.g. https://xyz.ngrok.app) because Safaricom must reach it.
        'callback_base_url' => (string) env('MPESA_CALLBACK_BASE_URL', ''),
        // Optional source allow-list for callbacks (comma-separated IPs or
        // CIDRs — Safaricom publishes its callback ranges). Empty = rely on
        // the per-account HMAC URL secret alone. Requires TRUSTED_PROXIES to
        // be right, or every caller looks like the load balancer.
        'callback_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('MPESA_CALLBACK_IPS', ''))))),
    ],

    /*
     * AlbaBook's own subscription billing — the platform's Daraja app, used
     * to collect plan invoices. Distinct from tenants' payment accounts.
     */
    'subscriptions' => [
        'consumer_key' => (string) env('MPESA_BILLING_CONSUMER_KEY', ''),
        'consumer_secret' => (string) env('MPESA_BILLING_CONSUMER_SECRET', ''),
        'passkey' => (string) env('MPESA_BILLING_PASSKEY', ''),
        'environment' => (string) env('MPESA_BILLING_ENVIRONMENT', 'sandbox'),
        // Lipa na M-Pesa shortcode (till for CustomerBuyGoodsOnline, paybill
        // otherwise) and the PartyB that receives the money.
        'shortcode' => (string) env('MPESA_BILLING_SHORTCODE', ''),
        'party_b' => (string) env('MPESA_BILLING_PARTY_B', ''),
        'buy_goods' => (bool) env('MPESA_BILLING_BUY_GOODS', true),
        // Signs the invoice callback URL; any high-entropy value works.
        'callback_secret' => (string) env('MPESA_BILLING_CALLBACK_SECRET', ''),
        // Grace days between an invoice's due date and read-only expiry.
        'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),
        // Days before period end / trial end to invoice or remind.
        'lead_days' => (int) env('BILLING_LEAD_DAYS', 5),
    ],
];
