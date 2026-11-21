# AlbaBook — Document Map and Decision Log

## 1. Document map and precedence

| Document | Authoritative for |
| -------- | ----------------- |
| `plan.md` | Product scope, architecture, stack, phases, Definition of Done. The overview everything else refines. |
| `booking-engine.md` | Availability, holds, concurrency, appointment lifecycle, rescheduling, booking payments/deposits, booking references and management links, public booking API. |
| `multi-tenancy.md` | Tenant resolution, `TenantContext`, tenant status policy, membership, domains, slugs, isolation patterns. |
| `auth.md` | Authentication, sessions, MFA, invitations, platform-admin login, support access. |
| `dashboard.md` | Tenant portal UI/UX, dashboard metrics definitions, navigation. |
| `decisions.md` | This file. Resolves conflicts between the others. |

Rule of precedence: when two documents disagree, the more specific document in the table above wins over `plan.md`, and a decision recorded below wins over all of them. Fix the losing document when you find a conflict; do not leave two answers in the docs.

The canonical permission catalogue is `plan.md` §14.2. Other documents must reference it, not define their own identifiers.

---

## 2. Decisions

Format: **context → decision → consequences.** Status is *Accepted* unless noted.

### D-001 Payment records exist from MVP

- **Context:** `dashboard.md` requires revenue "based on recorded payments" and says a confirmed appointment is not automatically paid, but `plan.md` §13 had no payments tables. M-Pesa automation is deferred.
- **Decision:** Add `payments`, `refunds` and `payment_provider_events` in Phase 4, with **manual** payment recording (cash, M-Pesa till/paybill, card terminal). Automated M-Pesa follows later and writes to the same tables. `appointments.payment_status` is a cached derivation of payment rows. See `booking-engine.md` §14.
- **Consequences:** Revenue reports are correct from day one. Customer payments (to the tenant) and subscription billing (tenant to AlbaTech: `subscription_invoices`, `subscription_payments`) are separate domains and never share tables.

### D-002 Booking page content is structured, not HTML

- **Context:** The booking page "Content" editor (description, FAQs, testimonials) suggested rich text, while `plan.md` §8.4/§19.2/§19.7 forbid rendering tenant HTML.
- **Decision:** All tenant content is **structured plain text**:
  - Text fields are plain text with line breaks, length-limited, rendered with Twig autoescape and `nl2br`.
  - FAQs are rows (`question`, `answer`, `sort_position`).
  - Testimonials are rows (`author_name`, `text`, `rating` 1–5 NULL, `source`, `sort_position`) and the tenant confirms they are genuine customer statements.
  - Links are separate validated URL fields (`https://` only), never embedded in text.
  - Content is part of the booking page draft and published version snapshot (`booking_page_versions`), so it previews, publishes and rolls back with the theme.
- **Consequences:** No HTML sanitizer is needed in MVP. If rich text is introduced later, it requires a new decision, a maintained sanitizer with a strict allowlist (paragraphs, bold, italic, lists, links), sanitisation on write **and** a CSP that blocks inline script.

### D-003 `tenant_settings` is a narrow store

- **Context:** `plan.md` §13.1 lists `tenant_settings` while §17.3 forbids one generic key/value table.
- **Decision:** `tenant_settings` holds only low-traffic, non-queried tenant preferences (date format, default dashboard view, onboarding progress, and the support-write toggle read when a support session is built), one row per tenant with typed columns plus a `preferences JSON` column validated against a versioned schema. Anything the application filters or joins on, or reads on every booking, gets its own table: `booking_policies`, `notification_preferences`, `tenant_branding`, `booking_page_settings`. The tenant's `default_locale` lives on `tenants` because messages and queries read it (D-017).
- **Consequences:** `TenantSettingsService` never becomes a dumping ground. Adding a key to `preferences` requires a schema version bump and validator update.

### D-004 MFA is part of the auth surface

- **Context:** `auth.md` requires optional MFA for owners and mandatory MFA for platform admins; `plan.md` §14 omitted it.
- **Decision:** TOTP (RFC 6238) plus one-time recovery codes in Phase 2. Mandatory for platform admins. Optional for tenant users, with a tenant-level setting to require it for roles holding `billing.manage`, `integrations.manage`, `settings.update`, `users.manage` or `roles.manage` (the list in `auth.md` §8c is canonical). Sensitive actions require recent re-authentication (step-up). Details in `auth.md` §8a–§8c.
- **Consequences:** Tables `user_mfa_factors`, `user_recovery_codes`; route `/two-factor`. WebAuthn/passkeys can be added later as another factor type.

### D-005 Canonical booking URL is host-based

- **Context:** `plan.md` used both `book.albabook.co.ke/{slug}` and `/book/{slug}`.
- **Decision:** The canonical public URL is `https://book.albabook.co.ke/{slug}`. On the `book.` host, routes are `/{slug}`, `/{slug}/services`, `/{slug}/manage/{token}`, etc. A verified custom domain serves the same routes without the slug (`https://booking.royalbeauty.co.ke/`). `app.albabook.co.ke/book/{slug}` is not a route.
- **Consequences:** Slugs share a namespace with platform paths on the `book.` host, so a **reserved slug list** is mandatory (`widget.js`, `assets`, `api`, `admin`, `app`, `manage`, `static`, `health`, `login`, `www`, …). Static assets on that host live under `/_/` (e.g. `/_/widget.js`, `/_/assets/…`). Slug changes keep the old slug in `tenant_slug_history` and redirect (301) for at least 12 months.

### D-006 Money is stored as integer minor units

- **Context:** `plan.md` §13 recommended `DECIMAL(12,2)`. PHP has no native decimal type; PDO returns DECIMAL as strings and arithmetic tends to slip into floats. Some currencies have 0 or 3 decimal places.
- **Decision:** Store amounts as `BIGINT` minor units (`amount_minor`) with an explicit `CHAR(3)` ISO 4217 currency. All arithmetic is integer. `CurrencyFormatter` converts using the currency's exponent.
- **Consequences:** Column names end in `_minor`. No floats anywhere in money paths; a PHPStan rule or test should enforce it.

### D-007 Roles are assigned per membership, not per user

- **Context:** `plan.md` §13.2 listed `user_roles`, which would let a role from one tenant apply in another for users with several memberships.
- **Decision:** Roles belong to a tenant (`roles.tenant_id`, NULL only for platform role templates copied at tenant creation). Assignments are `membership_roles (tenant_id, tenant_user_id, role_id)`. Branch-scoped access is `membership_branches (tenant_id, tenant_user_id, branch_id)`; no rows means all branches. A `staff` record links optionally to a membership via `staff.tenant_user_id`.
- **Consequences:** Permission resolution always starts from the active membership in `TenantContext`. `user_roles` is removed from the schema.

### D-008 Public and private API paths are separated

- **Context:** `plan.md` §12.3 had `POST /appointments` as both a public and a private endpoint on the same base URL, and public `GET /appointments/{reference}` exposed bookings by reference alone.
- **Decision:** Public endpoints live under `/v1/public/…`, keyed by slug and management token (`booking-engine.md` §18). Private endpoints live under `/v1/…`, authenticated by session or scoped API credential, and use UUIDs, never auto-increment ids.
- **Consequences:** A reference never grants access. Middleware stacks differ per prefix, which keeps rate limits and auth rules explicit.

### D-009 Booking writes serialise on staff/resource row locks at READ COMMITTED

- **Context:** `plan.md` §9.5 said "acquire availability guard locks" without defining them. MySQL cannot enforce non-overlapping intervals with constraints, and `REPEATABLE READ` snapshots can hide a concurrent commit from the re-check.
- **Decision:** `staff` and `resources` rows are lock anchors, locked `FOR UPDATE` first in the transaction, in a global order (staff by id, then resources by id), at `READ COMMITTED`. Holds, bookings, reschedules, leave and schedule edits all use the same protocol. See `booking-engine.md` §9.
- **Consequences:** Writers to the same staff member serialise; writers to different staff run in parallel. Throughput per staff member is bounded, which matches reality (one person, one appointment at a time).

### D-010 Tenant statuses are a fixed enum; the policy is data

- **Context:** `plan.md` §5.5 said statuses are "configurable"; `multi-tenancy.md` used an `ENUM` and a hardcoded policy array.
- **Decision:** The set of statuses is a fixed, code-level state machine (`pending`, `trial`, `active`, `suspended`, `cancelled`, `archived`) because code branches on them. What each status *allows* is data: `tenant_status_policies (status, operation, allowed)`, editable by platform admins, cached, with code defaults. See `multi-tenancy.md` §3.
- **Consequences:** Adding a new status is a code change and a migration; changing what a status permits is not.
- **Amended by D-023:** the status set is stored in the `tenant_statuses` lookup table (referenced by `tenants.status` and `tenant_status_policies.status`) instead of two copies of the same `ENUM`. It is still a code-level state machine: the PHP enum mirrors the seeded codes.

### D-011 Platform support access is explicit and audited

- **Context:** Admin login must never imply tenant access, but AlbaTech-assisted onboarding and support need to see tenant workspaces.
- **Decision:** Support access is a time-boxed (default 60 minutes), reason-required **support session** started from the admin app, recorded in `support_access_sessions`, shown as a banner in the tenant UI, visible to the tenant owner in their audit log, and read-only unless the admin has the `platform.support.write` permission **and** the tenant has not disabled it via `tenant_settings.support_write_enabled`. See `auth.md` §10.
- **Consequences:** No shared passwords, no "login as owner". The write toggle is tenant-owned, so a tenant can end write-capable support unilaterally without affecting read support.

### D-012 Personal data follows the Kenya Data Protection Act, 2019

- **Context:** The docs mention consent records but no data-protection requirements.
- **Decision:** AlbaTech is a data processor for tenants' customer data (tenants are controllers) and a controller for tenant user accounts. Requirements:
  - Consent captured per purpose (transactional vs marketing) and channel.
  - Customer data export and erasure on tenant request. Erasure **anonymises** customer records and appointment snapshots (`customer_name_snapshot`, contact fields) instead of deleting appointments, so financial and scheduling history stays consistent.
  - Retention periods configurable per data class (logs, notification payloads, expired holds, cancelled tenants' data), enforced by the cleanup worker.
  - Personal data is not written to application logs; phone numbers and emails are masked in logs.
  - Data processing terms are accepted by tenants at registration.
- **Consequences:** Phase 11 includes a data-protection review; confirm registration obligations with the ODPC and legal counsel before launch.

### D-013 Rate limiting and sessions use MySQL until Redis is justified

- **Context:** Rate limiting and DB-backed sessions are required, but Redis is a "later" item.
- **Decision:** `rate_limit_buckets (bucket_key, window_start, count)` with atomic `INSERT … ON DUPLICATE KEY UPDATE count = count + 1`, and DB-backed sessions in `user_sessions`. Both sit behind interfaces so a Redis implementation can replace them without touching callers.
- **Consequences:** Works on a single server and across multiple app servers from day one.

### D-014 SMS: Africa's Talking at launch, Infobip as later failover

- **Context:** `plan.md` §11.4 listed Africa's Talking, Infobip and Twilio without choosing.
- **Decision:** Launch with **Africa's Talking** (direct Safaricom/Airtel/Telkom routes, KES billing, delivery reports, PHP SDK, typically the lowest local cost). Add **Infobip** as a second adapter for failover once volume justifies it. Twilio is not used for Kenyan traffic.
  - Launch on one shared, registered alphanumeric sender ID ("AlbaBook"). Tenant-specific sender IDs are a later entitlement (`sms_custom_sender_id`).
  - Apply for sender ID registration with the networks at the start of Phase 8; Safaricom approval can take weeks.
- **Consequences:** Confirm the choice with a delivery test (a few hundred messages across all three networks, comparing delivery rate and latency) before signing a volume agreement. Provider selection stays configuration-only behind `NotificationProvider`.
- **Status:** Accepted, pending the delivery test.

### D-015 Prices are VAT-inclusive; AlbaBook does not issue tenant tax invoices in MVP

- **Context:** Kenyan consumers expect the displayed price to be the price paid. Many small tenants are below the VAT registration threshold. KRA eTIMS applies to tax invoices.
- **Decision:**
  - Tenant prices are entered and displayed **VAT-inclusive**. Tenants store `vat_registered`, `tax_rate_bp` (basis points, default 1600 when registered) and `kra_pin` (optional). Default: not VAT-registered, no tax breakdown shown.
  - When a tenant is VAT-registered, the tax portion is computed and snapshotted on each appointment item (`tax_rate_bp`, `tax_amount_minor`) at booking time.
  - AlbaBook confirmations and payment acknowledgements are labelled "Not a tax invoice". Tenants issue tax receipts through their own eTIMS. Tenant eTIMS integration (via KRA or a certified integrator) is a later premium entitlement.
  - **AlbaTech's own subscription invoices to tenants must be eTIMS-compliant from launch.** This belongs to the subscription billing module (Phase 10) and is not optional.
- **Consequences:** Confirm thresholds and obligations with a Kenyan tax advisor before launch; tax rules change often. Rates are data, never constants.

### D-016 M-Pesa uses each tenant's own till or paybill; AlbaTech never holds customer funds

- **Context:** An AlbaTech-aggregated collection account would mean holding and settling other businesses' money, likely requiring Central Bank of Kenya authorisation as a payment service provider and making AlbaTech liable for settlement, refunds and disputes.
- **Decision:** Customer payments go **directly to the tenant's own M-Pesa till (Buy Goods) or paybill**. Rollout:
  1. **MVP:** show the tenant's till/paybill and payment instructions at checkout and confirmation; staff record payments manually (D-001).
  2. **Paybill auto-matching:** customers use the booking reference as the account number; C2B confirmation callbacks match payments to bookings automatically. Tills have no account number, so till payments stay manual until step 3.
  3. **STK push:** using the tenant's own Daraja credentials (consumer key/secret, passkey, shortcode), encrypted at rest. AlbaTech offers assisted Daraja go-live onboarding.
- **Consequences:** Tables: `tenant_payment_accounts (tenant_id, provider, account_type till|paybill, shortcode, display_name, credentials_encrypted NULL, status)`. Unmatched C2B payments land in a review queue, never auto-attached by guesswork. Any future AlbaTech-run collection option goes through a licensed payment partner, never AlbaTech's own account.

### D-017 Languages: English everywhere, English + Swahili on the customer side

- **Context:** Business users are comfortable in English; customer booking completion is where language matters most.
- **Decision:**
  - Tenant dashboard and platform admin: English only at launch.
  - Customer booking page, confirmation, manage pages and SMS/WhatsApp/email templates: **English and Swahili** at launch.
  - i18n is built from day one even where only English ships: all UI strings in translation files (no literal strings in templates), `tenants.default_locale`, a customer-visible language switcher, and `appointments.locale` so messages go out in the language the customer booked in.
  - Translations are done by a human translator, especially policy wording. Machine translation is not used for shipped strings.
  - WhatsApp templates are submitted for Meta approval in both languages together.
- **Consequences:** Missing Swahili strings fall back to English and are reported by a CI check. Adding a language is translation work plus configuration, not code.

### D-018 Customer identity uniqueness is policy-driven, in a side table

- **Context:** `booking-engine.md` §16 made `customers.phone_e164` conditionally unique through `INSERT … ON DUPLICATE KEY`, but uniqueness depended on a policy value, and `phone_or_email` cannot be expressed as one composite unique key without nullable-column traps. Switching policy would have required a migration.
- **Decision:** `customers.phone_e164` and `customers.email` are indexed but **never unique**. Identity matching uses `customer_match_keys (tenant_id, match_type, match_value, customer_id)` with `UNIQUE (tenant_id, match_type, match_value)` and a composite FK to `customers (tenant_id, id)`. The `customer_match` policy decides which keys are written: `phone`, `email`, `phone_or_email` (both) or `none` (no keys). A duplicate-key collision during customer creation aborts the booking transaction; the retry re-reads and matches the existing customer.
- **Consequences:** No generated columns or partial-index emulation. Policy changes are staff reconciliation work, not schema changes. Duplicate merges move keys and repoint appointments.

### D-019 Auth and Dashboard are modules; Tenancy is foundational

- **Context:** `plan.md` §4 had `app/Auth/` services, `app/Tenancy/TenantAccessPolicy.php`, a `TenantMiddleware` in `app/Middleware/`, while `auth.md` and the dashboard spec used `app/Modules/Auth/` and `app/Modules/Dashboard/`. Two homes for the same code invites drift.
- **Decision:** Feature code lives in `app/Modules/<Feature>/`. `Auth` and `Dashboard` are modules. Tenancy is cross-cutting infrastructure and stays in `app/Tenancy/`, including `TenantContext`, resolvers, `TenantAccessChecker`, `TenantMiddleware` and `TenantSettingsService`. `app/Middleware/` keeps only middleware that is not tenant-specific (authentication, platform admin, permission, CSRF, rate limit, maintenance).
- **Consequences:** One import path per concept. `TenantAccessPolicy` is renamed `TenantAccessChecker` everywhere. `tests/` mirrors `app/`.

### D-020 Platform administration has its own sessions and permission scope

- **Context:** `auth.md` gave platform administrators a separate cookie and login but no session table, and `plan.md` §14.2 listed only tenant permissions while `auth.md` referenced `platform.support.write`.
- **Decision:** `platform_sessions` stores platform admin sessions separately from `user_sessions`. `permissions` carries `scope` (`'tenant'` or `'platform'`, enforced by `CHECK`); the platform catalogue in `plan.md` §14.2 (`platform.tenants.manage`, `platform.support.read`, `platform.support.write`, `platform.billing.manage`, `platform.integrations.manage`, `platform.audit.view`, `platform.config.manage`) is seeded with `scope = 'platform'` and can never be assigned to `membership_roles`. Platform permissions are granted per administrator through `platform_user_permissions` rather than through tenant-style roles. `webhook_endpoints.secret_encrypted` holds each endpoint's signing secret.
- **Consequences:** Admin authorization never falls back to tenant permissions, and support write access is an explicit, revocable platform permission. Because grants live in a separate table keyed by `platform_user_id`, they cannot leak into `role_permissions` or `membership_roles` by mistake. The separation is declarative: `role_permissions` and `platform_user_permissions` each carry a `permission_scope` column pinned by `CHECK` (`'tenant'` / `'platform'`) and reference `permissions (scope, id)`, so a mismatched grant fails even for direct SQL. (This replaced the original `BEFORE INSERT/UPDATE` triggers, which needed `DELIMITER` — a mysql-client command a PDO migration runner cannot run.)

### D-021 Schedule scopes are typed foreign keys, not polymorphic pairs

- **Context:** `booking-engine.md` §4.1 described `schedule_overrides` and `blocked_periods` with `scope_type`/`scope_id`, which cannot carry composite tenant-aware foreign keys and lets a row point at another tenant's branch.
- **Decision:** Both tables use nullable `branch_id`, `staff_id` and `resource_id` columns with a `CHECK` requiring exactly one non-null, and a composite FK per scope column to its parent's `(tenant_id, id)`.
- **Consequences:** Database-enforced tenant isolation, real referential integrity, and straightforward indexes per scope. Adding a fourth schedulable scope means a migration, which is appropriate.

### D-022 Unmatched M-Pesa payments are payment rows, not a separate table

- **Context:** `booking-engine.md` §14.2a named an "unmatched payments review queue" without defining storage.
- **Decision:** An unmatched C2B confirmation is a `payments` row with `status = 'unmatched'`, `appointment_id NULL` and `match_state` tracking `unmatched`/`matched`/`dismissed`. The queue is a filtered query. Unmatched rows are excluded from collected revenue and payment-status derivation until resolved.
- **Consequences:** One payment table, one audit trail. Resolution is an ordinary `payments.record`-style action with the matched appointment recorded.

---

### D-023 Lookup tables vs ENUMs

- **Context:** The first migrations used `ENUM` for nearly every coded value. Some of those were duplicated across tables (`tenants.status` and `tenant_status_policies.status`; the channel list in six notification tables), some needed metadata the schema could not hold (which appointment statuses block time, a currency's decimal places), and some were free-text `VARCHAR` with no integrity at all (`event_key`, `feature_key`, `metric_key`, `appointment_events.to_status`), where a typo silently disables behaviour. Changing an `ENUM` also means an `ALTER TABLE` on every table that repeats it.
- **Decision:** A coded value becomes a **lookup table** when **at least one** of these holds:
  1. it is referenced by more than one table;
  2. it carries behaviour or display metadata (flags, exponent, default order, entitlement);
  3. platform admins or tenants add values without a deploy;
  4. values differ per tenant (then the lookup is tenant-scoped).

  Otherwise it stays an `ENUM` (or a `CHECK`): a small, single-table, internal state that only code changes — processing statuses, token purposes, hold status, TLS status, policy options with dependent `CHECK`s.

  Lookup-table conventions:
  - Platform lookups use a stable ASCII `code` as the primary key, so referencing columns stay readable (`status = 'confirmed'`) and hot queries need no join. Tenant-scoped lookups use `(tenant_id, id)` with a `UNIQUE (tenant_id, code)`.
  - `name` is only the English default; the UI translates via `lookup.<table>.<code>` keys (D-017).
  - Rows are seeded in the migration (`000_reference_data.sql`) because the schema depends on them; they are not seeders.
  - Codes are never renamed or deleted once used; retire with `is_active = FALSE`.
  - Where code branches on the values, a PHP backed enum mirrors the codes and a test asserts the enum equals the seeded rows.

  Resulting lookups:

  | Lookup | Replaces | Why |
  | ------ | -------- | --- |
  | `currencies` | free `CHAR(3)` in 9 tables | shared; `minor_unit_exponent` drives `CurrencyFormatter` (D-006) |
  | `locales` | free `CHAR(5)` in 5 tables | shared; customer-facing vs dashboard flags (D-017) |
  | `tenant_statuses` | duplicated `ENUM` | shared; `is_terminal` |
  | `appointment_statuses` | `ENUM` + unchecked `VARCHAR` in events | shared; `blocks_time`, `is_terminal`, badge tone |
  | `communication_channels` | `ENUM` in 6 tables | shared |
  | `notification_events` | free-text `event_key` / `kind` | shared; transactional vs marketing, reminder flag |
  | `payment_methods` | two different `ENUM`s | shared by customer and subscription payments; new methods (Airtel Money) are data |
  | `payment_providers` | `ENUM('mpesa')` + free `VARCHAR`s | shared by accounts, payments, callbacks |
  | `features` | free-text `feature_key` / `metric_key` | shared; limit vs boolean; metered flag |
  | `booking_page_section_types` | `ENUM` | default order, default visibility, optional entitlement |
  | `staff_leave_types` (tenant-scoped) | `ENUM('annual','sick',…)` | differs per business |

  Kept as `ENUM` on purpose: `payment_status`, `booking_source`, `cancelled_by`, `actor_type`, every `booking_policies` option, hold/idempotency/queue/outbox/webhook processing statuses, token purposes, `tls_status`, domain status, resource/staff/user status, asset `kind`, consent `purpose`, `price_type`.
- **Consequences:** Adding a payment method, notification event, feature or currency is an `INSERT`, not an `ALTER`. Typos in event and feature keys fail at write time. Reference rows must be present before any tenant data, which is why they live in migration `000`.

### D-024 Tenants are never hard-deleted; tenant foreign keys are RESTRICT

- **Context:** Foreign keys to `tenants` mixed `CASCADE`, `SET NULL` and `RESTRICT`. A single `DELETE FROM tenants` could have silently wiped a business's services, assets and API credentials while failing on appointments — the worst of both. Separately, MySQL rejects `CASCADE`/`SET NULL` on a foreign key whose column is the base of a `STORED` generated column, which broke `roles`, `booking_page_versions` and `notification_templates` (all generate a column from `tenant_id`), and `booking_policies` (from `branch_id`).
- **Decision:** Tenants are archived and later anonymised (D-012), never deleted. **Every** foreign key to `tenants` is `ON DELETE RESTRICT`. Branches are deactivated (`is_active = FALSE`), not deleted, so `booking_policies → branches` is also `RESTRICT`. `CASCADE` remains only between a tenant-owned parent and its own detail rows (appointment → items, hold → hold items).
- **Consequences:** Accidental tenant deletion fails loudly. Data removal for a cancelled tenant is an explicit, audited retention job, not a cascade.

---

## 3. Open questions

None at present. Add new questions here with an owner and the phase that needs the answer.
