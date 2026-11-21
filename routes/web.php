<?php

declare(strict_types=1);

use App\Core\Routing\RouteRegistrar;
use App\Middleware\GuestOnlyMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Middleware\RequireAuthMiddleware;
use App\Tenancy\TenantMiddleware;
use App\Modules\Appointments\Controllers\AppointmentsApiController;
use App\Modules\Appointments\Controllers\AppointmentsPageController;
use App\Modules\Billing\Controllers\BillingApiController;
use App\Modules\Billing\Controllers\BillingPageController;
use App\Modules\Booking\Controllers\BookingEditorApiController;
use App\Modules\Booking\Controllers\BookingEditorPageController;
use App\Modules\Integrations\Controllers\IntegrationsApiController;
use App\Modules\Integrations\Controllers\IntegrationsPageController;
use App\Modules\Notifications\Controllers\NotificationsApiController;
use App\Modules\Notifications\Controllers\NotificationsPageController;
use App\Modules\Products\Controllers\ProductsApiController;
use App\Modules\Till\Controllers\ReceiptPageController;
use App\Modules\Till\Controllers\TillApiController;
use App\Modules\Reports\Controllers\ReportsApiController;
use App\Modules\Reports\Controllers\ReportsPageController;
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
use App\Modules\Branches\Controllers\BranchesApiController;
use App\Modules\Branches\Controllers\BranchesPageController;
use App\Modules\Customers\Controllers\CustomersApiController;
use App\Modules\Customers\Controllers\CustomersPageController;
use App\Modules\Dashboard\Controllers\DashboardApiController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\Platform\Controllers\SupportEntryController;
use App\Modules\Resources\Controllers\ResourcesApiController;
use App\Modules\Resources\Controllers\ResourcesPageController;
use App\Modules\Services\Controllers\ServicesApiController;
use App\Modules\Services\Controllers\ServicesPageController;
use App\Modules\Settings\Controllers\SettingsApiController;
use App\Modules\Settings\Controllers\SettingsPageController;
use App\Modules\Staff\Controllers\StaffApiController;
use App\Modules\Staff\Controllers\StaffPageController;
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
    $r->get('/services', [ServicesPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'services', options: ['permission' => 'services.view']);
    $r->get('/staff', [StaffPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'staff', options: ['permission' => 'staff.view']);
    $r->get('/customers', [CustomersPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'customers', options: ['permission' => 'customers.view']);
    $r->get('/settings', [SettingsPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'settings', options: ['permission' => 'settings.view']);
    $r->get('/branches', [BranchesPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'branches', options: ['permission' => 'branches.view']);
    $r->get('/resources', [ResourcesPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'resources', options: ['permission' => 'resources.view']);
    $r->get('/booking-page', [BookingEditorPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'booking-page', options: ['permission' => 'booking_page.view']);
    $r->get('/notifications', [NotificationsPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'notifications', options: ['permission' => 'notifications.view']);
    $r->get('/reports', [ReportsPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'reports', options: ['permission' => 'reports.view']);
    $r->get('/integrations', [IntegrationsPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'integrations', options: ['permission' => 'integrations.view']);
    $r->get('/billing', [BillingPageController::class, 'index'], [...$workspace, PermissionMiddleware::class], name: 'billing', options: ['permission' => 'billing.view']);

    // Private JSON API (session-authenticated, D-008: same host, UUID keys).
    $r->get('/api/dashboard/summary', [DashboardApiController::class, 'summary'], $workspace, name: 'api.dashboard.summary');
    $r->get('/api/filters', [AppointmentsApiController::class, 'filters'], $workspace, name: 'api.filters');
    $r->get('/api/appointments', [AppointmentsApiController::class, 'list'], $workspace, name: 'api.appointments.list');
    $r->post('/api/appointments', [AppointmentsApiController::class, 'create'], $workspace, name: 'api.appointments.create');
    $r->get('/api/appointments/{uuid}', [AppointmentsApiController::class, 'show'], $workspace, name: 'api.appointments.show');
    $r->post('/api/appointments/{uuid}/transition', [AppointmentsApiController::class, 'transition'], $workspace, name: 'api.appointments.transition');
    $r->get('/api/appointments/{uuid}/ticket', [TillApiController::class, 'ticket'], $workspace, name: 'api.till.ticket');
    $r->post('/api/appointments/{uuid}/items', [TillApiController::class, 'addItem'], $workspace, name: 'api.till.item.add');
    $r->patch('/api/appointments/{uuid}/items/{id}', [TillApiController::class, 'updateItem'], $workspace, name: 'api.till.item.update');
    $r->delete('/api/appointments/{uuid}/items/{id}', [TillApiController::class, 'removeItem'], $workspace, name: 'api.till.item.remove');
    $r->post('/api/appointments/{uuid}/payments', [TillApiController::class, 'pay'], $workspace, name: 'api.till.pay');
    $r->post('/api/appointments/{uuid}/payments/{payment_uuid}/refund', [TillApiController::class, 'refund'], $workspace, name: 'api.till.refund');
    $r->post('/api/appointments/{uuid}/checkout', [TillApiController::class, 'checkout'], $workspace, name: 'api.till.checkout');
    $r->get('/appointments/{uuid}/receipt', [ReceiptPageController::class, 'show'], [...$workspace, PermissionMiddleware::class], name: 'receipt', options: ['permission' => 'payments.view']);
    $r->get('/api/products', [ProductsApiController::class, 'list'], $workspace, name: 'api.products.list');
    $r->post('/api/products', [ProductsApiController::class, 'create'], $workspace, name: 'api.products.create');
    $r->patch('/api/products/{uuid}', [ProductsApiController::class, 'update'], $workspace, name: 'api.products.update');
    $r->delete('/api/products/{uuid}', [ProductsApiController::class, 'destroy'], $workspace, name: 'api.products.destroy');
    $r->patch('/api/settings/payments/policy', [SettingsApiController::class, 'updatePaymentPolicy'], $workspace, name: 'api.settings.payment-policy');
    $r->get('/api/calendar/events', [AppointmentsApiController::class, 'events'], $workspace, name: 'api.calendar.events');
    // Static segment before the {uuid} variable route (FastRoute shadowing).
    $r->get('/api/services/meta', [ServicesApiController::class, 'meta'], $workspace, name: 'api.services.meta');
    $r->get('/api/services', [ServicesApiController::class, 'list'], $workspace, name: 'api.services.list');
    $r->post('/api/services', [ServicesApiController::class, 'create'], $workspace, name: 'api.services.create');
    $r->get('/api/services/{uuid}', [ServicesApiController::class, 'show'], $workspace, name: 'api.services.show');
    $r->patch('/api/services/{uuid}', [ServicesApiController::class, 'update'], $workspace, name: 'api.services.update');
    $r->delete('/api/services/{uuid}', [ServicesApiController::class, 'destroy'], $workspace, name: 'api.services.destroy');
    $r->get('/api/staff/meta', [StaffApiController::class, 'meta'], $workspace, name: 'api.staff.meta');
    $r->get('/api/staff', [StaffApiController::class, 'list'], $workspace, name: 'api.staff.list');
    $r->post('/api/staff', [StaffApiController::class, 'create'], $workspace, name: 'api.staff.create');
    $r->get('/api/staff/{uuid}', [StaffApiController::class, 'show'], $workspace, name: 'api.staff.show');
    $r->patch('/api/staff/{uuid}', [StaffApiController::class, 'update'], $workspace, name: 'api.staff.update');
    $r->delete('/api/staff/{uuid}', [StaffApiController::class, 'destroy'], $workspace, name: 'api.staff.destroy');
    $r->get('/api/customers/meta', [CustomersApiController::class, 'meta'], $workspace, name: 'api.customers.meta');
    $r->get('/api/customers', [CustomersApiController::class, 'list'], $workspace, name: 'api.customers.list');
    $r->post('/api/customers', [CustomersApiController::class, 'create'], $workspace, name: 'api.customers.create');
    $r->get('/api/customers/{uuid}', [CustomersApiController::class, 'show'], $workspace, name: 'api.customers.show');
    $r->patch('/api/customers/{uuid}', [CustomersApiController::class, 'update'], $workspace, name: 'api.customers.update');
    $r->delete('/api/customers/{uuid}', [CustomersApiController::class, 'destroy'], $workspace, name: 'api.customers.destroy');
    $r->get('/api/settings/categories', [SettingsApiController::class, 'categories'], $workspace, name: 'api.settings.categories');
    $r->post('/api/settings/categories', [SettingsApiController::class, 'createCategory'], $workspace, name: 'api.settings.categories.create');
    $r->get('/api/settings', [SettingsApiController::class, 'show'], $workspace, name: 'api.settings.show');
    $r->patch('/api/settings', [SettingsApiController::class, 'update'], $workspace, name: 'api.settings.update');
    $r->patch('/api/settings/categories/{uuid}', [SettingsApiController::class, 'updateCategory'], $workspace, name: 'api.settings.categories.update');
    $r->delete('/api/settings/categories/{uuid}', [SettingsApiController::class, 'deleteCategory'], $workspace, name: 'api.settings.categories.delete');
    $r->patch('/api/settings/branding', [SettingsApiController::class, 'updateBranding'], $workspace, name: 'api.settings.branding');
    $r->get('/api/settings/notifications', [SettingsApiController::class, 'notifications'], $workspace, name: 'api.settings.notifications');
    $r->patch('/api/settings/notifications/preferences', [SettingsApiController::class, 'updateNotificationPref'], $workspace, name: 'api.settings.notifications.pref');
    $r->post('/api/settings/notifications/accounts', [SettingsApiController::class, 'createNotificationAccount'], $workspace, name: 'api.settings.notifications.accounts.create');
    $r->patch('/api/settings/notifications/accounts/{id}', [SettingsApiController::class, 'updateNotificationAccount'], $workspace, name: 'api.settings.notifications.accounts.update');
    $r->get('/api/settings/payments', [SettingsApiController::class, 'payments'], $workspace, name: 'api.settings.payments');
    $r->post('/api/settings/payments/accounts', [SettingsApiController::class, 'createPaymentAccount'], $workspace, name: 'api.settings.payments.accounts.create');
    $r->patch('/api/settings/payments/accounts/{uuid}', [SettingsApiController::class, 'updatePaymentAccount'], $workspace, name: 'api.settings.payments.accounts.update');
    $r->get('/api/branches', [BranchesApiController::class, 'list'], $workspace, name: 'api.branches.list');
    $r->post('/api/branches', [BranchesApiController::class, 'create'], $workspace, name: 'api.branches.create');
    $r->get('/api/branches/{uuid}', [BranchesApiController::class, 'show'], $workspace, name: 'api.branches.show');
    $r->patch('/api/branches/{uuid}', [BranchesApiController::class, 'update'], $workspace, name: 'api.branches.update');
    $r->delete('/api/branches/{uuid}', [BranchesApiController::class, 'destroy'], $workspace, name: 'api.branches.destroy');
    $r->get('/api/booking-page', [BookingEditorApiController::class, 'show'], $workspace, name: 'api.booking_page.show');
    $r->patch('/api/booking-page', [BookingEditorApiController::class, 'update'], $workspace, name: 'api.booking_page.update');
    $r->patch('/api/booking-page/sections', [BookingEditorApiController::class, 'updateSections'], $workspace, name: 'api.booking_page.sections');
    $r->post('/api/booking-page/faqs', [BookingEditorApiController::class, 'createFaq'], $workspace, name: 'api.booking_page.faqs.create');
    $r->patch('/api/booking-page/faqs/{id}', [BookingEditorApiController::class, 'updateFaq'], $workspace, name: 'api.booking_page.faqs.update');
    $r->delete('/api/booking-page/faqs/{id}', [BookingEditorApiController::class, 'deleteFaq'], $workspace, name: 'api.booking_page.faqs.delete');
    $r->post('/api/booking-page/publish', [BookingEditorApiController::class, 'publish'], $workspace, name: 'api.booking_page.publish');
    $r->get('/api/notifications', [NotificationsApiController::class, 'list'], $workspace, name: 'api.notifications.list');
    $r->post('/api/notifications/{uuid}/retry', [NotificationsApiController::class, 'retry'], $workspace, name: 'api.notifications.retry');
    $r->post('/api/notifications/{uuid}/cancel', [NotificationsApiController::class, 'cancel'], $workspace, name: 'api.notifications.cancel');
    $r->get('/api/reports', [ReportsApiController::class, 'summary'], $workspace, name: 'api.reports.summary');
    $r->get('/api/reports/export', [ReportsApiController::class, 'export'], $workspace, name: 'api.reports.export');
    $r->get('/api/integrations', [IntegrationsApiController::class, 'list'], $workspace, name: 'api.integrations.list');
    $r->post('/api/integrations', [IntegrationsApiController::class, 'create'], $workspace, name: 'api.integrations.create');
    $r->patch('/api/integrations/{uuid}', [IntegrationsApiController::class, 'update'], $workspace, name: 'api.integrations.update');
    $r->delete('/api/integrations/{uuid}', [IntegrationsApiController::class, 'destroy'], $workspace, name: 'api.integrations.destroy');
    $r->get('/api/billing', [BillingApiController::class, 'show'], $workspace, name: 'api.billing.show');
    $r->get('/api/resources/meta', [ResourcesApiController::class, 'meta'], $workspace, name: 'api.resources.meta');
    $r->get('/api/resources', [ResourcesApiController::class, 'list'], $workspace, name: 'api.resources.list');
    $r->post('/api/resources', [ResourcesApiController::class, 'create'], $workspace, name: 'api.resources.create');
    $r->get('/api/resources/{uuid}', [ResourcesApiController::class, 'show'], $workspace, name: 'api.resources.show');
    $r->patch('/api/resources/{uuid}', [ResourcesApiController::class, 'update'], $workspace, name: 'api.resources.update');
    $r->delete('/api/resources/{uuid}', [ResourcesApiController::class, 'destroy'], $workspace, name: 'api.resources.destroy');

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
