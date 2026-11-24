<?php

declare(strict_types=1);

namespace App\Modules\Auth;

/**
 * Canonical tenant permission catalogue (plan.md §14.2). Seeded into the
 * permissions table and mirrored by a test; never define identifiers elsewhere.
 */
enum TenantPermission: string
{
    case DashboardView = 'dashboard.view';

    case AppointmentsView = 'appointments.view';
    case AppointmentsViewOwn = 'appointments.view_own';
    case AppointmentsCreate = 'appointments.create';
    case AppointmentsUpdate = 'appointments.update';
    case AppointmentsConfirm = 'appointments.confirm';
    case AppointmentsReschedule = 'appointments.reschedule';
    case AppointmentsCancel = 'appointments.cancel';
    case AppointmentsOverridePolicy = 'appointments.override_policy';
    case AppointmentsOverrideConflict = 'appointments.override_conflict';
    case CalendarManageBlocks = 'calendar.manage_blocks';

    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersViewNotes = 'customers.view_notes';
    case CustomersExport = 'customers.export';

    case PaymentsView = 'payments.view';
    case PaymentsRecord = 'payments.record';
    case PaymentsRefund = 'payments.refund';
    /** Add discounts at the till (manager-level; recording payments alone is not enough). */
    case PaymentsDiscount = 'payments.discount';

    case ServicesView = 'services.view';
    case ServicesManage = 'services.manage';
    case StaffView = 'staff.view';
    case StaffManage = 'staff.manage';
    case BranchesView = 'branches.view';
    case BranchesManage = 'branches.manage';
    case ResourcesView = 'resources.view';
    case ResourcesManage = 'resources.manage';

    case BookingPageView = 'booking_page.view';
    case BookingPageManage = 'booking_page.manage';
    case BookingPagePublish = 'booking_page.publish';

    case ReportsView = 'reports.view';
    case ReportsViewFinancial = 'reports.view_financial';
    case ReportsExport = 'reports.export';

    case NotificationsView = 'notifications.view';
    case NotificationsManage = 'notifications.manage';
    case IntegrationsView = 'integrations.view';
    case IntegrationsManage = 'integrations.manage';
    case SettingsView = 'settings.view';
    case SettingsUpdate = 'settings.update';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case BillingView = 'billing.view';
    case BillingManage = 'billing.manage';
}
