<?php

declare(strict_types=1);

use App\Auth\PlatformPermission;
use App\Core\Routing\RouteRegistrar;
use App\Middleware\PlatformGuestOnlyMiddleware;
use App\Middleware\PlatformPermissionMiddleware;
use App\Middleware\PlatformRequireAuthMiddleware;
use App\Modules\Platform\Controllers\DashboardController;
use App\Modules\Platform\Controllers\LoginController;
use App\Modules\Platform\Controllers\LogoutController;
use App\Modules\Platform\Controllers\MfaEnrollmentController;
use App\Modules\Platform\Controllers\SupportSessionController;
use App\Modules\Platform\Controllers\TwoFactorController;
use App\Modules\System\Controllers\HomeController;

// Platform admin — admin.albabook.co.ke (auth.md §10, plan.md §18).
return static function (RouteRegistrar $r): void {
    $admin = [PlatformRequireAuthMiddleware::class];

    $r->get('/', [HomeController::class, 'admin'], name: 'admin.home');

    $r->get('/login', [LoginController::class, 'show'], [PlatformGuestOnlyMiddleware::class], name: 'admin.login');
    $r->post('/login', [LoginController::class, 'login'], [PlatformGuestOnlyMiddleware::class], name: 'admin.login.attempt');
    $r->post('/logout', [LogoutController::class, 'logout'], name: 'admin.logout');

    // Reachable while MFA is pending; the controllers enforce the state.
    $r->get('/two-factor', [TwoFactorController::class, 'show'], name: 'admin.two-factor');
    $r->post('/two-factor', [TwoFactorController::class, 'verify'], name: 'admin.two-factor.verify');
    $r->get('/mfa/enroll', [MfaEnrollmentController::class, 'show'], name: 'admin.mfa.enroll');
    $r->post('/mfa/enroll', [MfaEnrollmentController::class, 'confirm'], name: 'admin.mfa.confirm');

    $r->get('/dashboard', [DashboardController::class, 'index'], $admin, name: 'admin.dashboard');

    $r->post('/support-sessions', [SupportSessionController::class, 'start'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.support.start', options: ['platform_permission' => PlatformPermission::SupportRead->value]);
    $r->post('/support-sessions/{session}/end', [SupportSessionController::class, 'end'], $admin, name: 'admin.support.end');
};
