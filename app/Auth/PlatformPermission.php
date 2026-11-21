<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Platform catalogue (plan.md §14.2). Stored with permissions.scope =
 * 'platform' and granted only through platform_user_permissions.
 */
enum PlatformPermission: string
{
    case TenantsManage = 'platform.tenants.manage';
    case SupportRead = 'platform.support.read';
    case SupportWrite = 'platform.support.write';
    case BillingManage = 'platform.billing.manage';
    case IntegrationsManage = 'platform.integrations.manage';
    case AuditView = 'platform.audit.view';
    case ConfigManage = 'platform.config.manage';
}
