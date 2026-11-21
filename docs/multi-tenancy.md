### AlbaBook — Multi-Tenant Architecture & Tenant Checker

For AlbaBook, I recommend a shared database, shared schema and shared application codebase architecture.

Every business operates independently, but all tenants use the same application infrastructure. The tenant checker identifies the business associated with each request and establishes a trusted `TenantContext` before the request reaches application controllers.

The most important principle:

A tenant must never be identified or authorized using an untrusted `tenant_id` submitted by the browser.

### 1\. Multi-tenant architecture

![Multi-Tenancy, Layer by Layer: From Row-Level Security to Whole-Cluster Isolation - DEV Community](https://images.openai.com/static-rsc-4/r9Z_5_UrgZ0oCJvzNWECw0GgHGteX1HJ_iWp9v7FgoI7b6RN7_HVnhf2VbIUmFLZnxHsRwddLeUKRiS95bJRb62hnXN6-nJuk_VDJUnbkV3JcpzgXHf5LHFswwa1TOKytTa2lJbRGlIRODu7Q5gy556hhf0QoYGIiVOF-T4VihgmPJd5DsuOxy5UTfcEPSVo?purpose=fullsize)

```text
                    ALBABOOK PLATFORM
                           |
                  Incoming HTTP Request
                           |
                  Request Entry Point
                           |
                  Tenant Resolver
                           |
                 Tenant Identification
                           |
                 Tenant Access Checker
                           |
                  Tenant Context
                           |
                Authentication Middleware
                           |
                 Permission Middleware
                           |
                   Application Router
                           |
                Tenant-aware Services
                           |
                Tenant-aware Repositories
                           |
                    MySQL Database
                           |
              +------------+------------+
              |            |            |
           Tenant A     Tenant B     Tenant C
           Data         Data         Data
```

All tenant-owned records must be isolated through tenant-aware queries and database constraints.

### 2\. Tenant identification methods

AlbaBook has several entry points, so tenant resolution must support multiple identification methods.

| Application         | Tenant identification                    |
| ------------------- | ---------------------------------------- |
| Tenant dashboard    | Authenticated user's tenant membership   |
| Public booking page | Business slug                            |
| Custom domain       | Verified domain mapping                  |
| Private API         | Tenant-bound API credential              |
| Public API          | Business slug (no credential)            |
| Webhooks            | Verified endpoint and tenant association |
| Background jobs     | Trusted tenant ID stored in job payload  |

Example URLs:

```text
app.albabook.co.ke/dashboard
book.albabook.co.ke/royal-beauty
api.albabook.co.ke/v1/appointments
admin.albabook.co.ke
```

The platform admin application should have its own authentication and authorization boundary. It should not inherit tenant access simply because an administrator is logged in.

### 3\. Tenant checker request lifecycle

1\. Incoming request

HTTP request and hostname

2\. TenantResolver

Resolve slug, domain, membership or API credential

3\. TenantRepository

Load tenant and its current status

4\. TenantAccessChecker

Check active status, domain and access rules

Allowed

5\. Immutable TenantContext

Tenant identity available to authorized application services

6\. Application controller

Executes tenant-scoped operations

If a tenant is not found, suspended, inactive or otherwise unauthorized, the checker must stop the request before application operations execute.

### Tenant status policy

| Status    | Dashboard        | New bookings | Historical data |
| --------- | ---------------- | ------------ | --------------- |
| Pending   | Restricted       | No           | Retained        |
| Trial     | Allowed          | Yes          | Retained        |
| Active    | Allowed          | Yes          | Retained        |
| Suspended | Blocked          | No           | Retained        |
| Cancelled | Policy-dependent | No           | Retained        |
| Archived  | Blocked          | No           | Retained        |

Keep tenant status rules configurable through a centralized access policy rather than scattering status checks throughout controllers.

The six statuses are a fixed state machine in code, stored in the `tenant_statuses` lookup table that both `tenants.status` and `tenant_status_policies.status` reference (`decisions.md` D-023). What each status allows is data in `tenant_status_policies (status, operation, allowed)`, seeded from code defaults, editable by platform admins and cached (`decisions.md` D-010). "Policy-dependent" for `cancelled` means: read-only dashboard, billing and data export for a configurable grace period (default 30 days), then blocked.

Allowed transitions:

| From | To |
| ---- | -- |
| `pending` | `trial`, `active`, `archived` |
| `trial` | `active`, `suspended`, `cancelled` |
| `active` | `suspended`, `cancelled` |
| `suspended` | `active`, `cancelled` |
| `cancelled` | `active` (reactivation), `archived` |
| `archived` | — (terminal; data retained per retention policy, then anonymised) |

Every transition is made by `TenantLifecycleService`, audited, and emits an outbox event. Suspending a tenant does not cancel its existing appointments; the tenant decides, and customers keep access to their management links.

### 4\. Database structure

### Tenants table

Simplified excerpt; `database/migrations/001_platform_and_tenancy.sql` is the source of truth. `status`, `currency` and `default_locale` reference the lookup tables seeded in `000_reference_data.sql`, and every foreign key to `tenants` is `ON DELETE RESTRICT` (`decisions.md` D-023, D-024).

```sql
CREATE TABLE tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,

    status VARCHAR(20) NOT NULL DEFAULT 'pending',     -- FK tenant_statuses (code)

    timezone VARCHAR(64) NOT NULL DEFAULT 'Africa/Nairobi',
    currency CHAR(3) NOT NULL DEFAULT 'KES',          -- FK currencies (code)
    default_locale VARCHAR(10) NOT NULL DEFAULT 'en', -- FK locales (code)

    vat_registered BOOLEAN NOT NULL DEFAULT FALSE,
    tax_rate_bp SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    kra_pin VARCHAR(20) NULL,

    settings_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_tenants_status (status),
    CONSTRAINT chk_tenants_tax
        CHECK (vat_registered = 0 OR tax_rate_bp > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`default_locale` drives customer-facing message language (`decisions.md` D-017). `vat_registered`, `tax_rate_bp` and `kra_pin` are the tenant's own tax identity, used for VAT-inclusive price snapshots (`decisions.md` D-015); they are not payment settings. Full column list and seeding rules are in the migrations.

`DATETIME ... DEFAULT CURRENT_TIMESTAMP` is only UTC because every connection runs `SET time_zone = '+00:00'` (`booking-engine.md` §3). `timezone` and `currency` defaults come from `DEFAULT_TENANT_TIMEZONE` / `DEFAULT_TENANT_CURRENCY` at creation; the column defaults are a fallback only.

### Slugs

* Format: lowercase `a-z`, `0-9` and single hyphens, 3–60 characters, no leading or trailing hyphen.

* Reserved slugs are rejected at registration and rename: `_`, `admin`, `api`, `app`, `assets`, `book`, `help`, `login`, `manage`, `static`, `support`, `widget`, `www`, plus a platform-managed list. Static files on the booking host live under `/_/` so they can never collide with a slug (`decisions.md` D-005).

* Renaming a slug writes the old one to `tenant_slug_history (slug UNIQUE, tenant_id, replaced_at)`. Requests for an old slug return `301` to the current one for at least 12 months. An old slug cannot be claimed by another tenant during that period.

### Tenant domains

```sql
CREATE TABLE tenant_domains (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    domain VARCHAR(253) NOT NULL,          -- lowercase, punycode (IDNA) normalised

    status ENUM(
        'pending_verification',
        'verified',
        'active',
        'failed',
        'disabled'
    ) NOT NULL DEFAULT 'pending_verification',

    verification_token_hash CHAR(64) NOT NULL,
    verified_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    tls_status ENUM('none', 'pending', 'issued', 'failed') NOT NULL DEFAULT 'none',
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tenant_domain (domain),
    UNIQUE KEY uq_tenant_domain_pair (tenant_id, id),

    CONSTRAINT fk_domain_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

This table holds **custom domains only**. Platform hosts (`app.`, `book.`, `api.`, `admin.`) are a fixed allowlist in configuration, and tenants on `book.` are resolved by slug.

Only `active` domains resolve tenants. Re-verify DNS periodically (e.g. daily); a domain whose record disappears moves to `failed` and stops resolving after a grace period, with an alert to the tenant. When a domain is active, the slug URL stays valid and the custom domain is the canonical URL.

### Tenant membership

A user may belong to more than one business. Do not store a single `tenant_id` directly on the users table as the sole membership relationship.

```sql
CREATE TABLE tenant_users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    status ENUM(
        'invited',
        'active',
        'suspended',
        'removed'
    ) NOT NULL DEFAULT 'invited',

    invited_by_user_id BIGINT UNSIGNED NULL,
    joined_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tenant_user (tenant_id, user_id),
    UNIQUE KEY uq_tenant_user_pair (tenant_id, id),

    CONSTRAINT fk_tenant_users_tenant
        FOREIGN KEY (tenant_id)
        REFERENCES tenants(id),

    CONSTRAINT fk_tenant_users_user
        FOREIGN KEY (user_id)
        REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Roles and branch access are always assigned to the membership, never to the user (`decisions.md` D-007):

```sql
CREATE TABLE membership_roles (
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, tenant_user_id, role_id),
    FOREIGN KEY (tenant_id, tenant_user_id) REFERENCES tenant_users (tenant_id, id),
    FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE membership_branches (
    tenant_id BIGINT UNSIGNED NOT NULL,
    tenant_user_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (tenant_id, tenant_user_id, branch_id),
    FOREIGN KEY (tenant_id, tenant_user_id) REFERENCES tenant_users (tenant_id, id),
    FOREIGN KEY (tenant_id, branch_id) REFERENCES branches (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

The composite foreign keys make it impossible to give a membership a role or branch belonging to another tenant. `roles` are tenant-owned; platform role templates are copied into each new tenant at creation, so tenants can edit their roles without affecting others.

`TenantContext` is complemented by a request-scoped `MembershipContext` (membership id, permission set, branch scope) built by the permission middleware for dashboard requests.

### 5\. Core PHP implementation

Recommended module structure:

```text
app/
├── Tenancy/
│   ├── TenantContext.php
│   ├── TenantResolver.php
│   ├── TenantRepository.php
│   ├── TenantAccessChecker.php
│   ├── TenantMiddleware.php
│   ├── TenantDomainResolver.php
│   ├── TenantMembershipRepository.php
│   └── Exceptions/
│       ├── TenantNotFoundException.php
│       ├── TenantSuspendedException.php
│       └── TenantAccessDeniedException.php
│
├── Middleware/
│   ├── AuthenticationMiddleware.php
│   ├── PermissionMiddleware.php
│   └── PlatformAdminMiddleware.php
│
└── Core/
    └── Database.php
```

### A. TenantContext.php

The context holds the tenant identity for the current request. It should be immutable after construction.

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

final readonly class TenantContext
{
    public function __construct(
        public int $tenantId,
        public string $uuid,
        public string $name,
        public string $slug,
        public string $timezone,
        public string $currency,
        public string $status
    ) {}
}
```

### B. TenantRepository.php

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use PDO;

final class TenantRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {}

    public function findById(int $tenantId): ?array
    {
        $sql = "
            SELECT id, uuid, name, slug,
                   timezone, currency, status
            FROM tenants
            WHERE id = :id
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $tenantId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        $sql = "
            SELECT id, uuid, name, slug,
                   timezone, currency, status
            FROM tenants
            WHERE slug = :slug
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByDomain(string $domain): ?array
    {
        $sql = "
            SELECT t.id, t.uuid, t.name, t.slug,
                   t.timezone, t.currency, t.status
            FROM tenants t
            INNER JOIN tenant_domains d
                ON d.tenant_id = t.id
            WHERE d.domain = :domain      -- normalised by TenantDomainResolver first
              AND d.status = 'active'
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['domain' => $domain]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
```

### C. TenantAccessChecker.php

```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Tenancy\Exceptions\TenantAccessDeniedException;

final class TenantAccessChecker
{
    public function assertCanAccess(
        array $tenant,
        string $operation
    ): void {
        $status = $tenant['status'];

        if ($status === 'suspended' || $status === 'archived') {
            throw new TenantAccessDeniedException(
                'This business account is unavailable.'
            );
        }

        $policies = [
            'pending' => [
                'dashboard.view',
                'onboarding.manage',
            ],
            'trial' => [
                'dashboard.view',
                'booking.create',
                'business.manage',
            ],
            'active' => ['*'],
            'cancelled' => [
                'billing.view',
                'data.export',
            ],
        ];

        $allowed = $policies[$status] ?? [];

        if (!in_array('*', $allowed, true)
            && !in_array($operation, $allowed, true)) {
            throw new TenantAccessDeniedException(
                'This operation is not available.'
            );
        }
    }
}
```

This is an illustrative centralized policy. In production, the policy matrix is loaded from `tenant_status_policies` (cached, with these code defaults as fallback) rather than hardcoded, and the result is combined with subscription entitlements, user permissions, branch access and the requested operation. A valid tenant status alone does not authorize every user action.

Operation names in the policy are coarse account-level capabilities (`dashboard.view`, `booking.create`, `business.manage`, `billing.view`, `data.export`, `onboarding.manage`), not the user permission identifiers in `plan.md` §14.2.

### D. TenantMiddleware.php

The middleware should resolve tenant identity based on the route type.

For authenticated tenant dashboard routes:

* Require an authenticated user.

* Resolve the tenant selected for the current session.

* Confirm active membership.

* Load the tenant.

* Validate tenant status.

* Set the request's `TenantContext`.

* Continue to the next middleware.

For public booking routes:

* Extract the business slug from the matched route.

* Resolve the tenant.

* Check that the public booking page is enabled.

* Check booking eligibility.

* Set the tenant context.

Do not use the public booking route resolver to establish dashboard authorization.

### 6\. Tenant isolation in repositories

Every tenant-owned database query must be scoped.

Incorrect:

```php
$stmt = $pdo->prepare(
    "SELECT * FROM appointments WHERE id = :id"
);
```

Correct:

```php
$stmt = $pdo->prepare("
    SELECT *
    FROM appointments
    WHERE tenant_id = :tenant_id
      AND id = :appointment_id
    LIMIT 1
");

$stmt->execute([
    'tenant_id' => $tenantContext->tenantId,
    'appointment_id' => $appointmentId,
]);
```

For updates:

```php
$stmt = $pdo->prepare("
    UPDATE appointments
    SET status = :status
    WHERE tenant_id = :tenant_id
      AND id = :appointment_id
");

$stmt->execute([
    'status' => $status,
    'tenant_id' => $tenantContext->tenantId,
    'appointment_id' => $appointmentId,
]);
```

Never rely on globally unique IDs as a substitute for tenant isolation.

### Stronger database protection

Use composite foreign keys wherever practical:

```sql
ALTER TABLE appointments
ADD UNIQUE KEY uq_appointment_tenant_id (tenant_id, id);
```

A child table can then reference both columns:

```sql
FOREIGN KEY (tenant_id, appointment_id)
REFERENCES appointments (tenant_id, id)
```

This prevents a child record from accidentally referencing another tenant's appointment.

### 7\. Important security checks

| Threat                            | Required protection                           |
| --------------------------------- | --------------------------------------------- |
| Changing tenant ID in request     | Ignore submitted tenant identity              |
| Accessing another tenant's record | Tenant-scoped queries                         |
| Switching business accounts       | Validate membership on every request          |
| Forged custom domain              | Verified domain ownership                     |
| Host header manipulation          | Trusted host allowlist                        |
| Suspended tenant access           | Central status policy                         |
| Cross-tenant background job       | Trusted job tenant context                    |
| Cache data leakage                | Tenant-aware cache keys                       |
| API credential misuse             | Hashed credentials, scopes and tenant binding |
| Cross-tenant foreign keys         | Composite tenant-aware constraints            |

Additional requirements:

* Use a trusted proxy configuration; do not blindly trust forwarded host headers.

* Normalize and validate domain names before lookup.

* Use CSRF protection for state-changing browser requests.

* Recheck authorization inside services for sensitive operations.

* Never reuse a tenant context between background jobs.

* Add automated tests that deliberately attempt cross-tenant reads and writes.

### Background workers

Long-running workers process many tenants in one PHP process, so context leakage is a real risk:

* Each job row stores `tenant_id`. The worker loads the tenant, runs the status check, builds a fresh `TenantContext` and passes it explicitly into the job handler.

* Tenant-aware services used by workers are resolved from a **per-job** container scope, never cached on a long-lived singleton.

* After each job (success or failure), the scope is discarded and any tenant-level cache in memory is cleared.

* A job whose tenant is suspended or archived is skipped or deferred according to the status policy — for example, cancellation notices may still go out, marketing never does.

### Support access

Platform administrators reach a tenant workspace only through an audited support session (`auth.md` §10, `decisions.md` D-011). The tenant middleware builds the `TenantContext` from the support session record, never from a request parameter, and marks the context as `actorType = support_session` so audit logs and the UI banner can show it. A write-scoped session only yields write access when the administrator also holds `platform.support.write` and the tenant has `tenant_settings.support_write_enabled = 1`; otherwise the context is downgraded to read.

### 8\. Tenant switching

A user who belongs to multiple businesses should be able to switch workspaces.

Royal Beauty Studio

Current workspace

Active

Switch workspace

Urban Cuts Barbershop

Administrator

Wellness Spa

Staff member

When switching:

* Verify the target membership.

* Verify membership status and tenant status.

* Update the authenticated session's active tenant.

* Regenerate the session ID where appropriate.

* Rebuild the request context.

* Reload tenant-specific permissions and branch assignments.

Never accept the selected tenant ID without checking membership against the authenticated user.

### 9\. Tenant checker test cases

Before launching AlbaBook, implement automated tests for these cases:

* Tenant A cannot access Tenant B's appointments.

* Tenant A cannot update Tenant B's customers.

* A user cannot switch to a tenant they do not belong to.

* Suspended tenants cannot create new bookings.

* A verified custom domain resolves to the correct tenant.

* An unverified custom domain cannot resolve.

* A user removed from a tenant immediately loses access.

* Public booking pages cannot expose private tenant data.

* API keys cannot access another tenant.

* Background jobs cannot leak context across tenants.

* Cache entries cannot be shared across tenant boundaries.

* A role or branch from tenant B cannot be assigned to a membership in tenant A (composite FK rejects it).

* A user with memberships in A and B has only A's permissions while A is active.

* Reserved slugs are rejected; a renamed slug redirects and cannot be claimed by another tenant.

* A domain that is `verified` but not `active`, or has failed re-verification, does not resolve.

* A worker processing jobs for A then B never uses A's context for B's job.

* A support session expires on time, and a read-only support session cannot write.

### 10\. Recommended implementation architecture

Keep these responsibilities separate:

| Component                  | Responsibility                        |
| -------------------------- | ------------------------------------- |
| TenantResolver             | Identify the tenant                   |
| TenantRepository           | Retrieve tenant records               |
| TenantAccessChecker        | Evaluate tenant account access        |
| TenantMembershipRepository | Validate user membership              |
| TenantContext              | Store trusted request tenant identity |
| TenantMiddleware           | Establish context before controllers  |
| TenantPolicy               | Apply account-level business rules    |
| PermissionMiddleware       | Check user-level permissions          |
| TenantAwareRepository      | Enforce tenant-scoped database access |

One important architectural decision: Do not build the tenant checker as a single giant middleware containing domain resolution, authentication, permissions, subscription checks and database queries. Separate those responsibilities so each can be tested independently.

The tenant checker should be a foundational part of AlbaBook, implemented before appointments, services, staff and customer modules. Every module should depend on the same trusted tenant context.