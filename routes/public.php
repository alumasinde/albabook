<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\Booking\Controllers\BookingPageController;
use App\Modules\Booking\Controllers\ManageBookingController;
use App\Modules\Booking\Controllers\WidgetController;
use App\Modules\System\Controllers\HomeController;

// Public booking — book.albabook.co.ke. /{slug} resolves the tenant by its
// booking-page slug (decisions.md D-005); JSON endpoints live under the slug.
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'booking'], name: 'booking.home');
    // Widget loader script + framed page — registered before /{slug}
    // patterns; '_' is a reserved slug so it can never collide.
    $r->get('/_/widget.js', [WidgetController::class, 'script'], name: 'booking.widget.js');
    $r->get('/{slug}/embed', [BookingPageController::class, 'embed'], name: 'booking.embed');
    $r->get('/{slug}/availability', [BookingPageController::class, 'availability'], name: 'booking.availability');
    $r->get('/{slug}/staff', [BookingPageController::class, 'staffList'], name: 'booking.staff');
    $r->post('/{slug}/book', [BookingPageController::class, 'book'], name: 'booking.book');
    $r->post('/{slug}/verify-phone', [BookingPageController::class, 'sendPhoneCode'], name: 'booking.verify_phone');
    $r->post('/{slug}/verify-phone/check', [BookingPageController::class, 'checkPhoneCode'], name: 'booking.verify_phone.check');
    // Customer self-service — the manage token is the capability (D-008).
    $r->get('/{slug}/manage/{token}', [ManageBookingController::class, 'page'], name: 'booking.manage');
    $r->post('/{slug}/manage/{token}/cancel', [ManageBookingController::class, 'cancel'], name: 'booking.manage.cancel');
    $r->post('/{slug}/manage/{token}/reschedule', [ManageBookingController::class, 'reschedule'], name: 'booking.manage.reschedule');
    // Verified custom domains (plan.md §24): the same routes without the slug
    // segment — the host identifies the tenant. These literals are reserved
    // slugs so they can never shadow a tenant page on the shared host.
    $r->get('/embed', [BookingPageController::class, 'embed'], name: 'booking.domain.embed');
    $r->get('/availability', [BookingPageController::class, 'availability'], name: 'booking.domain.availability');
    $r->get('/staff', [BookingPageController::class, 'staffList'], name: 'booking.domain.staff');
    $r->post('/book', [BookingPageController::class, 'book'], name: 'booking.domain.book');
    $r->post('/verify-phone', [BookingPageController::class, 'sendPhoneCode'], name: 'booking.domain.verify_phone');
    $r->post('/verify-phone/check', [BookingPageController::class, 'checkPhoneCode'], name: 'booking.domain.verify_phone.check');
    $r->get('/manage/{token}', [ManageBookingController::class, 'page'], name: 'booking.domain.manage');
    $r->post('/manage/{token}/cancel', [ManageBookingController::class, 'cancel'], name: 'booking.domain.manage.cancel');
    $r->post('/manage/{token}/reschedule', [ManageBookingController::class, 'reschedule'], name: 'booking.domain.manage.reschedule');
    $r->get('/{slug}', [BookingPageController::class, 'page'], name: 'booking.page');
};
