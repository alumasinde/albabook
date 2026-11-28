<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Modules\Api\Controllers\PrivateApiController;
use App\Modules\Api\Controllers\PublicBookingApiController;
use App\Modules\Api\Middleware\ApiCredentialMiddleware;
use App\Modules\Staff\Controllers\StaffCalendarController;
use App\Modules\System\Controllers\HomeController;

// Public API — api.albabook.co.ke (booking-engine.md §18, decisions.md
// D-008). Public and private endpoints can never collide: everything under
// /v1/public/ is slug- or token-capability-scoped, while private /v1/*
// routes require a scoped API credential. Public payloads carry uuids and
// references, never auto-increment ids.
return static function (RouteRegistrar $r): void {
    $r->get('/', [HomeController::class, 'api'], name: 'api.home');

    $r->get('/v1/public/businesses/{slug}', [PublicBookingApiController::class, 'business'], name: 'api.public.business');
    $r->get('/v1/public/businesses/{slug}/services', [PublicBookingApiController::class, 'services'], name: 'api.public.services');
    $r->get('/v1/public/businesses/{slug}/branches', [PublicBookingApiController::class, 'branches'], name: 'api.public.branches');
    $r->get('/v1/public/businesses/{slug}/availability/days', [PublicBookingApiController::class, 'availabilityDays'], name: 'api.public.availability.days');
    $r->get('/v1/public/businesses/{slug}/availability/slots', [PublicBookingApiController::class, 'availabilitySlots'], name: 'api.public.availability.slots');
    $r->post('/v1/public/businesses/{slug}/holds', [PublicBookingApiController::class, 'createHold'], name: 'api.public.holds.create');
    $r->delete('/v1/public/businesses/{slug}/holds/{holdToken}', [PublicBookingApiController::class, 'releaseHold'], name: 'api.public.holds.release');
    $r->post('/v1/public/businesses/{slug}/bookings', [PublicBookingApiController::class, 'createBooking'], name: 'api.public.bookings.create');
    $r->post('/v1/public/businesses/{slug}/phone-verifications', [PublicBookingApiController::class, 'sendPhoneCode'], name: 'api.public.phone_verifications.create');
    $r->post('/v1/public/businesses/{slug}/phone-verifications/check', [PublicBookingApiController::class, 'checkPhoneCode'], name: 'api.public.phone_verifications.check');
    $r->post('/v1/public/businesses/{slug}/booking-lookup', [PublicBookingApiController::class, 'bookingLookup'], name: 'api.public.bookings.lookup');

    $r->get('/v1/public/bookings/{manageToken}', [PublicBookingApiController::class, 'bookingShow'], name: 'api.public.booking.show');
    $r->post('/v1/public/bookings/{manageToken}/cancel', [PublicBookingApiController::class, 'bookingCancel'], name: 'api.public.booking.cancel');
    $r->post('/v1/public/bookings/{manageToken}/reschedule', [PublicBookingApiController::class, 'bookingReschedule'], name: 'api.public.booking.reschedule');
    $r->get('/v1/public/bookings/{manageToken}/calendar.ics', [PublicBookingApiController::class, 'bookingCalendar'], name: 'api.public.booking.calendar');
    // Per-staff .ics subscription feed — the token is the capability.
    $r->get('/v1/staff-calendar/{token}.ics', [StaffCalendarController::class, 'feed'], name: 'api.staff-calendar.feed');

    // Private API — Bearer credential + per-route scope (D-008, plan.md
    // §12.3). The tenant is the credential's tenant; uuids in URLs.
    $private = [ApiCredentialMiddleware::class];
    $r->get('/v1/appointments', [PrivateApiController::class, 'appointments'], $private, name: 'api.appointments', options: ['scope' => 'appointments:read']);
    $r->post('/v1/appointments', [PrivateApiController::class, 'appointmentCreate'], $private, name: 'api.appointments.create', options: ['scope' => 'appointments:write']);
    $r->get('/v1/appointments/{uuid}', [PrivateApiController::class, 'appointmentShow'], $private, name: 'api.appointments.show', options: ['scope' => 'appointments:read']);
    $r->post('/v1/appointments/{uuid}/confirm', [PrivateApiController::class, 'appointmentConfirm'], $private, name: 'api.appointments.confirm', options: ['scope' => 'appointments:write']);
    $r->post('/v1/appointments/{uuid}/cancel', [PrivateApiController::class, 'appointmentCancel'], $private, name: 'api.appointments.cancel', options: ['scope' => 'appointments:write']);
    $r->post('/v1/appointments/{uuid}/reschedule', [PrivateApiController::class, 'appointmentReschedule'], $private, name: 'api.appointments.reschedule', options: ['scope' => 'appointments:write']);
    $r->get('/v1/services', [PrivateApiController::class, 'services'], $private, name: 'api.services', options: ['scope' => 'services:read']);
    $r->get('/v1/staff', [PrivateApiController::class, 'staffList'], $private, name: 'api.staff', options: ['scope' => 'staff:read']);
    $r->get('/v1/customers', [PrivateApiController::class, 'customers'], $private, name: 'api.customers', options: ['scope' => 'customers:read']);
    $r->get('/v1/reports/summary', [PrivateApiController::class, 'reportsSummary'], $private, name: 'api.reports.summary', options: ['scope' => 'reports:read']);
};
