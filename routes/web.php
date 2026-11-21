<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Middleware\GuestOnlyMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RequireAuthMiddleware;
use App\Tenancy\TenantMiddleware;
use App\Modules\Appointments\Controllers\AppointmentsApiController;
use App\Modules\Appointments\Controllers\AppointmentsPageController;
use App\Modules\Auth\Controllers\EmailVerificationController;
use App\Modules\Auth\Controllers\InvitationController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\LogoutController;
use App\Modules\Auth\Controllers\PasswordResetController;
use App\Modules\Auth\Controllers\RegistrationController;
use App\Modules\Auth\Controllers\SecurityController;
use App\Modules\Auth\Controllers\TeamController;
use App\Modules\Auth\Controllers\TwoFactorController;
use App\Modules\Auth\Controllers\TwoFactorEnrollmentController;
use App\Modules\Auth\Controllers\WorkspaceController;
use App\Modules\Dashboard\Controllers\DashboardApiController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Platform\Controllers\SupportEntryController;
use App\Modules\System\Controllers\HomeController;
use App\Modules\Tenants\Controllers\OnboardingController;

// Tenant portal — app.albabook.co.ke (plan.md §18).
return static function (RouteRegistrar $r): void {
    $auth = [RequireAuthMiddleware::class];
    $workspace = [RequireAuthMiddleware::class, TenantMiddleware::class];

    $r->get('/', [HomeController::class, 'tenant'], name: 'tenant.home');

    $r->get('/login', [LoginController::class, 'show'], [GuestOnlyMiddleware::class], name: 'login');
    $r->post('/login', [LoginController::class, 'login'], [GuestOnlyMiddleware::class], name: 'login.attempt');
    $r->post('/logout', [LogoutController::class, 'logout'], name: 'logout');

    $r->get('/register', [RegistrationController::class, 'show'], [GuestOnlyMiddleware::class], name: 'register');
    $r->post('/register', [RegistrationController::class, 'register'], [GuestOnlyMiddleware::class], name: 'register.store');

    $r->get('/forgot-password', [PasswordResetController::class, 'showRequest'], [GuestOnlyMiddleware::class], name: 'password.request');
    $r->post('/forgot-password', [PasswordResetController::class, 'send'], [GuestOnlyMiddleware::class], name: 'password.email');
    $r->get('/reset-password/{token}', [PasswordResetController::class, 'showReset'], [GuestOnlyMiddleware::class], name: 'password.reset');
    $r->post('/reset-password/{token}', [PasswordResetController::class, 'reset'], [GuestOnlyMiddleware::class], name: 'password.update');

    $r->get('/verify-email', [EmailVerificationController::class, 'pending'], name: 'verification.notice');
    $r->post('/verify-email/resend', [EmailVerificationController::class, 'resend'], $auth, name: 'verification.send');

    $r->get('/invite/{token}', [InvitationController::class, 'show'], name: 'invitation.show');
    $r->post('/invite/{token}', [InvitationController::class, 'accept'], name: 'invitation.accept');

    // The challenge page must be reachable while the session is MFA-pending;
    // its controller decides between challenge and enrolment.
    $r->get('/two-factor', [TwoFactorController::class, 'show'], name: 'two-factor.show');
    $r->post('/two-factor', [TwoFactorController::class, 'verify'], name: 'two-factor.verify');
    $r->get('/two-factor/enroll', [TwoFactorEnrollmentController::class, 'show'], $auth, name: 'two-factor.enroll');
    $r->post('/two-factor/enroll', [TwoFactorEnrollmentController::class, 'confirm'], $auth, name: 'two-factor.confirm');

    $r->get('/workspaces', [WorkspaceController::class, 'index'], $auth, name: 'workspaces');
    $r->post('/workspaces/{tenant}/switch', [WorkspaceController::class, 'switch'], $auth, name: 'workspaces.switch');

    $r->get('/onboarding/business', [OnboardingController::class, 'show'], $auth, name: 'onboarding.business');
    $r->post('/onboarding/business', [OnboardingController::class, 'create'], $auth, name: 'onboarding.business.store');

    $r->get('/dashboard', [DashboardController::class, 'index'], $workspace, name: 'dashboard');

    $r->get('/appointments', [AppointmentsPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'appointments', options: ['permission' => 'appointments.view']);
    $r->get('/calendar', [AppointmentsPageController::class, 'calendar'], [...$workspace, PermissionMiddleware::class], name: 'calendar', options: ['permission' => 'appointments.view']);

    // Private JSON API (session-authenticated, D-008: same host, UUID keys).
    $r->get('/api/dashboard/summary', [DashboardApiController::class, 'summary'], $workspace, name: 'api.dashboard.summary');
    $r->get('/api/filters', [AppointmentsApiController::class, 'filters'], $workspace, name: 'api.filters');
    $r->get('/api/appointments', [AppointmentsApiController::class, 'list'], $workspace, name: 'api.appointments.list');
    $r->post('/api/appointments', [AppointmentsApiController::class, 'create'], $workspace, name: 'api.appointments.create');
    $r->get('/api/appointments/{uuid}', [AppointmentsApiController::class, 'show'], $workspace, name: 'api.appointments.show');
    $r->post('/api/appointments/{uuid}/transition', [AppointmentsApiController::class, 'transition'], $workspace, name: 'api.appointments.transition');
    $r->get('/api/calendar/events', [AppointmentsApiController::class, 'events'], $workspace, name: 'api.calendar.events');

    $r->get('/security', [SecurityController::class, 'index'], $auth, name: 'security');
    $r->post('/security/password', [SecurityController::class, 'changePassword'], $auth, name: 'security.password');
    $r->post('/security/mfa/disable', [SecurityController::class, 'disableMfa'], $auth, name: 'security.mfa.disable');

    $r->get('/team', [TeamController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'team', options: ['permission' => 'users.manage']);
    $r->post('/team/invitations', [TeamController::class, 'invite'], [...$workspace, PermissionMiddleware::class], name: 'team.invite', options: ['permission' => 'users.manage']);
    $r->post('/team/invitations/{invitation}/revoke', [TeamController::class, 'revoke'], [...$workspace, PermissionMiddleware::class], name: 'team.revoke', options: ['permission' => 'users.manage']);

    // Audited support entry/exit. The token is verified again before the
    // tenant-host cookie is set (multi-tenancy.md §7).
    $r->get('/support/enter', [SupportEntryController::class, 'enter'], name: 'support.enter');
    $r->get('/support/exit', [SupportEntryController::class, 'exit'], name: 'support.exit');
};
