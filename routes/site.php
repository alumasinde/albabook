<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\Marketing\Controllers\MarketingController;
use App\Modules\Till\Controllers\ReceiptVerifyController;

/*
 * Public marketing site on the bare domain (APP_HOST_MARKETING,
 * albabook.co.ke). No sessions or tenant context; sign-up and sign-in links
 * point at the app host.
 */
return static function (RouteRegistrar $r): void {
    $r->get('/', [MarketingController::class, 'home'], name: 'site.home');
    $r->get('/features', [MarketingController::class, 'features'], name: 'site.features');
    $r->get('/pricing', [MarketingController::class, 'pricing'], name: 'site.pricing');
    $r->get('/contact', [MarketingController::class, 'contact'], name: 'site.contact');
    $r->post('/contact', [MarketingController::class, 'submitContact'], name: 'site.contact.submit');
    // Receipt QR target: confirms a printed receipt is genuine.
    $r->get('/verify/{code}', [ReceiptVerifyController::class, 'show'], name: 'site.receipt.verify');
};
