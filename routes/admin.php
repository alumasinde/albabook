<?php

declare(strict_types=1);

use App\Modules\Auth\PlatformPermission;
use App\Core\Routing\RouteRegistrar;
use App\Middleware\PlatformGuestOnlyMiddleware;
use App\Middleware\PlatformPermissionMiddleware;
use App\Middleware\PlatformRequireAuthMiddleware;
use App\Modules\Platform\Controllers\AuditLogsController;
use App\Modules\Platform\Controllers\DashboardController;
use App\Modules\Platform\Controllers\LeadsController;
use App\Modules\Platform\Controllers\LoginController;
use App\Modules\Platform\Controllers\PlansController;
use App\Modules\Platform\Controllers\LogoutController;
use App\Modules\Platform\Controllers\MfaEnrollmentController;
use App\Modules\Platform\Controllers\SupportSessionController;
use App\Modules\Platform\Controllers\TenantsController;
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

    // Demo requests from the marketing site.
    $r->get('/leads', [LeadsController::class, 'index'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.leads', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);
    $r->post('/leads/{uuid}/status', [LeadsController::class, 'updateStatus'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.leads.status', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);

    $billing = [...$admin, PlatformPermissionMiddleware::class];
    $r->get('/plans', [PlansController::class, 'index'], $billing, name: 'admin.plans', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->get('/plans/new', [PlansController::class, 'createForm'], $billing, name: 'admin.plans.new', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->post('/plans', [PlansController::class, 'create'], $billing, name: 'admin.plans.create', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->get('/plans/{uuid}', [PlansController::class, 'edit'], $billing, name: 'admin.plans.edit', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->post('/plans/{uuid}', [PlansController::class, 'update'], $billing, name: 'admin.plans.update', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->post('/plans/{uuid}/toggle', [PlansController::class, 'toggle'], $billing, name: 'admin.plans.toggle', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->post('/plans/{uuid}/delete', [PlansController::class, 'destroy'], $billing, name: 'admin.plans.delete', options: ['platform_permission' => PlatformPermission::BillingManage->value]);

    $r->get('/audit-logs', [AuditLogsController::class, 'index'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.audit', options: ['platform_permission' => PlatformPermission::AuditView->value]);

    $r->post('/support-sessions', [SupportSessionController::class, 'start'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.support.start', options: ['platform_permission' => PlatformPermission::SupportRead->value]);
    $r->post('/support-sessions/{session}/end', [SupportSessionController::class, 'end'], $admin, name: 'admin.support.end');

    $r->get('/tenants/{uuid}', [TenantsController::class, 'show'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.show', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);
    $r->post('/tenants/{uuid}/plan', [TenantsController::class, 'assignPlan'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.plan', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
    $r->post('/tenants/{uuid}/status', [TenantsController::class, 'updateStatus'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.status', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);
    $r->post('/tenants/{uuid}/overrides', [TenantsController::class, 'setOverride'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.overrides', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);
    $r->post('/tenants/{uuid}/overrides/{feature}/clear', [TenantsController::class, 'clearOverride'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.overrides.clear', options: ['platform_permission' => PlatformPermission::TenantsManage->value]);
    $r->post('/tenants/{uuid}/subscription/cancel', [TenantsController::class, 'cancelSubscription'], [...$admin, PlatformPermissionMiddleware::class], name: 'admin.tenants.subscription.cancel', options: ['platform_permission' => PlatformPermission::BillingManage->value]);
};
