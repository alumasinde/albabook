<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * Stable audit action names (auth.md §12). Kept as constants so queries and
 * dashboards can rely on exact strings.
 */
final class AuditAction
{
    public const REGISTERED = 'auth.registered';
    public const LOGIN_SUCCEEDED = 'auth.login_succeeded';
    public const LOGIN_FAILED = 'auth.login_failed';
    public const LOGOUT = 'auth.logout';
    public const PASSWORD_CHANGED = 'auth.password_changed';
    public const PASSWORD_RESET_REQUESTED = 'auth.password_reset_requested';
    public const PASSWORD_RESET = 'auth.password_reset';
    public const EMAIL_VERIFIED = 'auth.email_verified';
    public const MFA_ENROLLED = 'auth.mfa_enrolled';
    public const MFA_DISABLED = 'auth.mfa_disabled';
    public const MFA_RECOVERY_USED = 'auth.mfa_recovery_used';
    public const SESSION_REVOKED = 'auth.session_revoked';

    public const TENANT_REGISTERED = 'tenant.registered';
    public const TENANT_SLUG_RENAMED = 'tenant.slug_renamed';

    public const INVITATION_SENT = 'invitation.sent';
    public const INVITATION_RESENT = 'invitation.resent';
    public const INVITATION_ACCEPTED = 'invitation.accepted';
    public const INVITATION_REVOKED = 'invitation.revoked';

    public const PLATFORM_LOGIN_SUCCEEDED = 'platform.login_succeeded';
    public const PLATFORM_LOGIN_FAILED = 'platform.login_failed';
    public const PLATFORM_LOGOUT = 'platform.logout';
    public const PLATFORM_MFA_ENROLLED = 'platform.mfa_enrolled';

    public const SUPPORT_STARTED = 'support.started';
    public const SUPPORT_ENDED = 'support.ended';

    public const PLAN_ASSIGNED = 'platform.plan_assigned';
}
