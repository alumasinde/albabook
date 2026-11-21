For AlbaBook, the authentication UI should have a premium SaaS appearance consistent with the tenant dashboard, while keeping customer booking authentication separate.

I recommend designing the authentication experience around three user groups:

* Business owners and employees.

* Customers.

* AlbaTech platform administrators.

Customers should not be forced to create accounts to book appointments.

### 1\. Authentication pages and routes

| Page                    | URL                        | User                    |
| ----------------------- | -------------------------- | ----------------------- |
| Login                   | /login                     | Tenant users            |
| Register                | /register                  | New business owners     |
| Forgot password         | /forgot-password           | Tenant users            |
| Reset password          | /reset-password/{token}    | Tenant users            |
| Verify email            | /verify-email              | New users               |
| Accept invitation       | /invite/{token}            | Invited employees       |
| Two-factor verification | /two-factor                | Protected accounts      |
| Platform admin login    | admin.albabook.co.ke/login | AlbaTech administrators |

Customer booking should use guest checkout by default. If customer accounts are introduced later, they should have a separate authentication experience.

### 2\. Login page

Primary authentication screen

![50+ login page examples for SaaS designers \[2026\]](https://images.openai.com/static-rsc-4/73JjE_36mtlKA9BwA9XPpZpRa8Wqh-X8gHsyxQR7f5QuMcaggdFOEnyiUHAq6i1E_zaAE1_OnRC7ZiGhU9pA0VaXlwgEZ89CtFGxfkt8BEfY271sOdAarKuvsn6L8YHrMkCHRZiSvaZc8AETfs8e6B_sPJsmXdup60QnrFlL_YxUC0a5QgkPgKU9zGxNCAGm?purpose=fullsize)

### Recommended layout

Left section

* AlbaBook logo.

* Welcome back heading.

* Email address.

* Password.

* Remember me.

* Forgot password.

* Sign in button.

* Create business account link.

Right section

* Premium visual illustration.

* Short product statement.

* Appointment management benefits.

* Subtle AlbaBook branding.

On mobile, hide the decorative section and use a clean single-column form.

### 3\. Registration page

![Marketplace Dashboard designs, themes, templates and downloadable graphic elements on Dribbble](https://images.openai.com/static-rsc-4/okxJsiCR9ToXFxw7acoje8ERWKBM_IvpYe690PvllO-KiudZk1TOUuVEQYG5aU1s2oCFKt_9_VkCn_3J-rG0KipEUzDy0CurSeJ9UgofOJDBS4ZgR8AlC-pdEkgii8jdNBW9_WpYUlJnmH3FkCn0ZCvvAN48V0wStalGlCWSmsHzkodhJO0Bkg51hAMyj3qu?purpose=fullsize)

Registration should be simple. Do not ask for all business information at once.

Fields:

* First name.

* Middle name (optional).

* Last name.

* Business email.

* Phone number.

* Password.

* Confirm password.

* Terms and privacy acceptance.

After registration, take the owner through a business setup wizard.

Recommended flow:

* Create account.

* Verify email.

* Business information.

* Select business category.

* Configure branch.

* Add first service.

* Add staff.

* Configure working hours.

* Customize booking page.

* Publish.

### 4\. Forgot and reset password

![Forgot Password Webflow Components | Memberstack | Memberstack](https://images.openai.com/static-rsc-4/81ZeSavALwm8o5WZGAzH3D5nWayhx3l0zIUhmq2Sbf2ic2klzO_AhfhWyVjRYm8_fMLzc7Xyb2wcOJgSVWLTei71fKQ1KiE6vCxj6YHhBkcPnJNNP08JVzzN0sukIca7yEZwUa4lRfsGFy2meJAPmpB3Aunbp4Q30PgxxEEvX8-KY787owkp9HEQAkV1abAi?purpose=fullsize)

Forgot password:

* Email address.

* Send reset link.

* Return to login.

Reset password:

* New password.

* Confirm password.

* Password requirements.

* Save password.

Use single-use, expiring reset tokens stored securely as hashes.

Always show a generic response to prevent email enumeration.

### 5\. Accept invitation

When an owner invites a receptionist, manager or employee, they receive a secure invitation link.

The page should display:

* Business name.

* Inviting administrator.

* Assigned role.

* Invitation expiry.

* Create password.

* Accept invitation.

Do not allow invited users to choose their own elevated permissions.

Invitation rules:

* An invitation is bound to one email address, one tenant and one role set. It is stored in `tenant_invitations` with a hashed single-use token and an expiry (default 7 days).

* **New user:** the page asks for name and password, creates the user, marks the email verified (the link proved ownership) and activates the membership.

* **Existing user:** the page asks them to sign in instead of creating a password. The invitation is accepted only if the signed-in user's verified email matches the invited email. A new membership is added; their password and other memberships are untouched.

* Revoking an invitation, or removing a member, takes effect on the next request.

* Re-sending an invitation invalidates the previous token.

### 5a. Login with several workspaces

After login, a user with one active membership goes straight to that workspace. A user with several sees a workspace picker. The selected tenant is stored in the server-side session, never in a cookie or URL, and is re-validated against `tenant_users` on every request (see `multi-tenancy.md` §8).

### 6\. Authentication design system

AlbaBook visual identity

Navy

#171A2C

Violet

#6D5CE8

Teal

#16A085

Background

#F3F4F8

Typography

* Inter font family.

* Large, readable headings.

* 16px minimum form text.

* Clear labels and validation messages.

Form components

* Consistent input heights.

* Rounded corners.

* Visible focus states.

* Password visibility toggle.

* Inline validation.

* Accessible error messages.

* Loading and disabled states.

### 7\. Interactive login UI concept

Here is a functional visual prototype of the login experience.

### AlbaBook

### Your business, beautifully organized.

Manage appointments, customers, teams and business operations from one place.

### Welcome back

Sign in to access your business workspace.

Business email

Password

Remember me

Forgot password?

Sign in

New to AlbaBook? Create a business account

Secure business access · Powered by AlbaTech Solutions

Interactive frontend concept only. Authentication is not connected to a backend.

### 8\. Backend implementation requirements

Suggested module:

```text
app/Modules/Auth/
├── Controllers/
│   ├── LoginController.php
│   ├── RegisterController.php
│   ├── PasswordController.php
│   ├── VerificationController.php
│   └── InvitationController.php
├── Services/
│   ├── AuthenticationService.php
│   ├── RegistrationService.php
│   ├── PasswordResetService.php
│   ├── EmailVerificationService.php
│   └── InvitationService.php
├── Repositories/
│   ├── UserRepository.php
│   └── PasswordResetRepository.php
├── Validators/
├── DTOs/
└── Policies/
```

Security requirements:

* PDO prepared statements.

* `password_hash()` and `password_verify()`.

* Session regeneration after login.

* Secure, HttpOnly and SameSite cookies.

* CSRF protection.

* Login rate limiting.

* Generic forgot-password responses.

* Hashed, single-use reset and invitation tokens.

* Email verification.

* Optional MFA for business owners and mandatory MFA policy for platform administrators.

* Audit logging for sensitive authentication events.

* Tenant membership checks on every protected request.

### 8a. Sessions and cookies

* Sessions are stored server-side in `user_sessions` (session id hash, user id, active tenant id, IP, user agent, created, last seen, MFA-verified-at, revoked-at). Users can see and revoke their sessions; changing a password revokes all other sessions.

* Idle timeout 2 hours, absolute timeout 12 hours (configurable in `config/security.php`).

* Cookies are host-only (no `Domain` attribute), `Secure`, `HttpOnly`, `SameSite=Lax`, and use the `__Host-` prefix: `__Host-albabook_app` on `app.`, `__Host-albabook_admin` on `admin.`. The booking host sets no session cookie for tenant users.

* Session id is regenerated on login, MFA completion, workspace switch and privilege change.

* **Remember me** issues a separate long-lived token (30 days) stored hashed in `remember_tokens`, rotated on every use and revoked on logout or password change. A remembered login never satisfies MFA or step-up on its own.

### 8b. Passwords and login protection

* Minimum 12 characters, no composition rules, maximum 128. Reject the 10,000 most common passwords (bundled list). An optional breached-password check must use a k-anonymity API and fail open.

* `password_hash()` with `PASSWORD_ARGON2ID` where available, otherwise `PASSWORD_DEFAULT`; call `password_needs_rehash()` on login.

* Rate limit by account and by IP (`rate_limit_buckets`). After repeated failures, add increasing delays instead of a hard lockout, so attackers cannot lock owners out.

* Login, forgot-password and registration responses are identical whether or not the email exists.

### 8c. Multi-factor authentication

* Factor: TOTP (RFC 6238), 30-second step, ±1 step tolerance, last used step stored to prevent replay. Secret encrypted at rest with the application encryption key.

* Ten single-use recovery codes, shown once, stored hashed in `user_recovery_codes`.

* Enrolment requires the current password and one valid code.

* **Platform administrators:** MFA mandatory; no access to the admin app until enrolled.

* **Tenant users:** optional. A tenant can require MFA for any role holding `billing.manage`, `integrations.manage`, `settings.update`, `users.manage` or `roles.manage`.

* **Step-up:** creating API credentials, changing roles or billing, exporting customer data and disabling MFA require a password or MFA confirmation within the last 10 minutes.

* Resetting a lost factor: owners use a recovery code; otherwise AlbaTech support performs an audited identity check. Disabling MFA notifies the user by email.

* WebAuthn/passkeys may be added later as another factor type in `user_mfa_factors`.

### 8d. Email verification and email changes

* Verification tokens are hashed, single-use and expire after 24 hours (`email_verification_tokens`).

* Unverified owners may finish onboarding but cannot publish the booking page or invite users.

* Changing an email requires the password, sends a confirmation link to the new address and a notice to the old one, and only takes effect after confirmation. The pending change is stored in `email_change_tokens` (user, new address, hashed token, expiry); `users.email` is only updated when the link is confirmed, and any other active change token for that user is invalidated.

### 9\. Twig authentication views

```text
resources/views/
├── layouts/
│   └── auth.twig
├── auth/
│   ├── login.twig
│   ├── register.twig
│   ├── forgot-password.twig
│   ├── reset-password.twig
│   ├── verify-email.twig
│   ├── accept-invitation.twig
│   └── two-factor.twig
└── components/
    └── forms/
        ├── input.twig
        ├── password.twig
        ├── checkbox.twig
        └── form-error.twig
```

All authentication pages should use shared components so that changing the input design, validation appearance or button styling updates the entire authentication experience.

### 10\. Platform administrators and support access

* Platform administrators are stored in `platform_users`, separate from `users`, with their own sessions in `platform_sessions`, their own cookie (`__Host-albabook_admin`) and login at `admin.albabook.co.ke/login`. Being a platform admin never grants tenant access.

* Admin permissions are a separate catalogue stored in `permissions` with `scope = 'platform'`; they are never assigned to tenant roles. Identifiers are listed in `plan.md` §14.2. A platform session carries the resolved platform permissions, exactly as a tenant membership carries its own.

* Admin login may additionally be restricted by IP allowlist (`config/security.php`).

* **Support access** (`decisions.md` D-011): to view a tenant's workspace, an admin starts a support session from the admin app with a reason and a duration (default 60 minutes, maximum 4 hours). It is recorded in `support_access_sessions` (admin, tenant, reason, scope `read` or `write`, started, expires, ended).

  * The tenant UI shows a persistent banner during a support session.

  * Every action is audited with actor type `support_session`.

  * The tenant owner can see support sessions in their audit log.

  * Write scope needs the `platform.support.write` permission, and a tenant can disable write access entirely.

  * Support sessions cannot read API secrets, change the owner, change billing or export customer data.

### 11\. Customer authentication

Customers do not have accounts in MVP. Their access to a booking is the management link (`booking-engine.md` §15), optionally protected by OTP verification at booking time and for manage lookup (`booking_policies.customer_verification`). OTP codes are 6 digits, stored hashed in `booking_verification_codes` along with a hash of the contact (never the contact itself), the purpose (`booking` or `manage_lookup`), expiry (10 minutes), an attempt counter capped at 5, and a consumed timestamp. They are rate limited per phone/email and IP. Rows are scoped to the tenant so one business's code can never satisfy another's flow.

If optional customer accounts are introduced, they use a separate `customer_accounts` table, a separate cookie on the booking host and passwordless login (email or SMS link/OTP). They never share tables or sessions with tenant users.

### 12\. Authentication tables

| Table | Purpose |
| ----- | ------- |
| `users` | Tenant-side user identity (name, email, verified-at, password hash). No `tenant_id`. |
| `tenant_users` | Membership: user ↔ tenant with status. |
| `membership_roles`, `membership_branches` | Roles and branch scope per membership (`decisions.md` D-007). |
| `user_sessions` | Server-side sessions. |
| `remember_tokens` | Hashed remember-me tokens. |
| `user_mfa_factors`, `user_recovery_codes` | MFA. |
| `email_verification_tokens`, `email_change_tokens`, `password_reset_tokens` | Hashed, single-use, expiring. |
| `tenant_invitations` | Invitations bound to email, tenant and roles. |
| `tenant_invitation_roles` | Roles granted by an invitation. |
| `login_attempts`, `rate_limit_buckets` | Throttling and audit of failed logins. |
| `platform_users`, `platform_user_mfa_factors`, `platform_user_recovery_codes` | AlbaTech administrators. |
| `platform_sessions` | Server-side sessions for platform administrators, separate from `user_sessions`. |
| `platform_user_permissions` | Direct platform-permission grants to a platform administrator (`permissions.scope = 'platform'`). |
| `booking_verification_codes` | Hashed, expiring OTPs for guest booking verification and manage lookup (§11). |
| `support_access_sessions` | Audited support access. |

Authentication events to audit: login success/failure, logout, password change/reset, MFA enrol/disable/recovery-code use, email change, invitation sent/accepted/revoked, role change, session revocation, support session start/end.

Recommended next step: Build the complete authentication UI first, including login, registration, forgot password, reset password, email verification and invitation acceptance, before connecting the forms to the authentication services.