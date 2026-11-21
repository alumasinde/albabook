<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\Booking\Controllers\BookingPageController;
use App\Modules\System\Controllers\HomeController;

// Public booking — book.albabook.co.ke. /{slug} resolves the tenant by its
// booking-page slug (decisions.md D-005); JSON endpoints live under the slug.
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'booking'], name: 'booking.home');
    $r->get('/{slug}/availability', [BookingPageController::class, 'availability'], name: 'booking.availability');
    $r->get('/{slug}/staff', [BookingPageController::class, 'staffList'], name: 'booking.staff');
    $r->post('/{slug}/book', [BookingPageController::class, 'book'], name: 'booking.book');
    $r->get('/{slug}', [BookingPageController::class, 'page'], name: 'booking.page');
};
