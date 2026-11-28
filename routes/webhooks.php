<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\Billing\Controllers\BillingWebhookController;
use App\Modules\Payments\Controllers\MpesaCallbackController;

// Inbound provider callbacks — api host (no session, CSRF-exempt by host).
// Each URL carries a per-account/invoice HMAC secret; see MpesaStkService
// and SubscriptionBillingService.
return static function (RouteRegistrar $r): void {
    $r->post('/webhooks/mpesa/stk/{account}/{signature}', [MpesaCallbackController::class, 'stk'], name: 'webhooks.mpesa.stk');
    $r->post('/webhooks/billing/mpesa/{invoice}/{signature}', [BillingWebhookController::class, 'mpesa'], name: 'webhooks.billing.mpesa');
};
