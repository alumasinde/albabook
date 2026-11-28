# AlbaBook

Multi-tenant appointment booking and business management for salons, spas,
clinics and other service businesses in Kenya, by AlbaTech Solutions.

Core PHP 8.3 · MySQL 8 · Twig · Tailwind · vanilla JS. Specifications live in
[`docs/`](docs/), starting with [`docs/plan.md`](docs/plan.md) and the
decision log [`docs/decisions.md`](docs/decisions.md). What is built and what
is left is tracked in [`update.md`](update.md); production setup is in
[`docs/deployment.md`](docs/deployment.md).

**Status:** every launch (P0) item in `update.md` is built. Before going live,
run the test suite on MySQL 8, do one Safaricom sandbox payment end to end, and
spot-check the booking page on a phone over 3G.

## What it does

**For customers (booking host)**
- Public booking page per business (`book.…/{slug}`), embeddable widget and
  custom domains (off by default), in English and Swahili.
- Live availability from opening hours, staff schedules, breaks, time off,
  closures, blocked time, buffers, rooms and equipment.
- Optional **phone confirmation by SMS code** before booking (rate-limited,
  10-minute codes, signed token; skipped automatically if SMS is unavailable).
- Manage link to cancel or reschedule within the business's cutoffs. Links
  expire 30 days after the visit; nothing can be changed once the visit has
  started.

**For businesses (tenant host)**
- **Guided setup** (`/setup`): location and hours → service → team or
  "I work alone" → working hours → booking rules → verify e-mail → go live.
  A dashboard checklist covers anything skipped, and the page cannot go live
  until it can actually take a booking. New businesses start as `pending`
  (setup only); going live moves them to `trial` and starts the 14-day free
  trial from that moment. No manual approval; AlbaBook can still suspend.
- **Booking rules:** confirm online bookings automatically (default) or
  approve each one; unanswered requests expire. Notice, advance window, slot
  interval, cancel / reschedule cutoffs, phone confirmation.
- Dashboard with a "Needs approval" queue (one-tap confirm / decline),
  calendar, appointments, customers with history and duplicate merge.
- **Time Off & Closures:** weekly breaks, time off with request / approval,
  branch closures and special hours, blocked time.
- **Team:** invite, change roles (never beyond your own access), limit to
  branches, deactivate (signs the person out at once), reactivate.
- Till: split payments, discounts, refunds, configurable receipts with a
  verification QR; **M-Pesa STK push**; **KRA eTIMS** tax invoices.
- Notifications by SMS, e-mail and WhatsApp (AlbaBook's number or the
  business's own), reminders, and alerts to the team for new online bookings
  and customer cancellations.
- Reports, CSV export, webhooks and an API for integrations.
- **Subscription billing:** trial, plan choice, invoices paid by M-Pesa,
  prorated upgrades, read-only after an unpaid grace period.

**For AlbaBook (admin host)**
- Tenant directory and lifecycle, plans and feature overrides, audited
  support access, audit log, leads from the marketing site.

**Marketing site** on the bare domain (`albabook.localhost` locally).

## Hosts

Each interface has its own host (D-005). `*.localhost` resolves to 127.0.0.1,
so no hosts-file edits are needed.

| Interface | Local URL |
| --- | --- |
| Marketing site | http://albabook.localhost:8080 |
| Business dashboard | http://app.albabook.localhost:8080 |
| Public booking | http://book.albabook.localhost:8080 |
| API and webhooks | http://api.albabook.localhost:8080 |
| Platform admin | http://admin.albabook.localhost:8080 |

Docker (`APP_PORT`) and `composer serve` both use port 8080, so run one or
the other. Any other host answers `400 Unknown host`.

## Quick start (Docker)

MySQL 8 is required in Docker and CI: the booking engine's locking and the
migrations depend on MySQL 8 behaviour (D-009, D-024).

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php bin/console key:generate
docker compose exec app php bin/console migrate          # 31 migrations (000–030)
docker compose exec app php bin/console db:seed          # permissions, role templates, plans, tenant status policies
docker compose exec app php bin/console platform:admin --email=you@example.com --first-name=You --last-name=Admin
docker compose exec app php bin/console demo:seed        # optional demo business (local only)
npm ci && npm run build                                  # CSS, JS, fonts and icons into public/assets
```

MySQL is also exposed on `localhost:33060` (`DB_FORWARD_PORT`).

### Without Docker

Point `.env` at a local MySQL 8 (`DB_HOST=127.0.0.1`), run the same console
commands with plain `php bin/console …`, then `composer serve`. MariaDB runs
for local development, but the test suite and production must use MySQL 8.

### Demo data

`demo:seed` creates **Royal Beauty** (booking page `/royal-beauty` on the
booking host):

| Account | E-mail | Access |
| --- | --- | --- |
| Owner | `owner@demo.albabook.test` | everything |
| Staff | `staff@demo.albabook.test` | own appointments only |

Both use the password `demo-password-1`.

## Background work

Run the scheduler every minute. It dispatches notifications and reminders,
outbox and webhook deliveries, expires unapproved booking requests, settles
M-Pesa prompts whose callback never arrived, submits eTIMS invoices, runs
subscription billing (trial reminders, invoices, grace and expiry), cleanup
and the night audit.

```bash
* * * * * php /path/to/albabook/cron/scheduler.php
```

`workers/*.php` (`notifications`, `outbox`, `reminders`, `webhooks`,
`cleanup`) can run as supervised long-lived processes instead. `/health`
reports how fresh the scheduler, workers and backups are.

## Testing M-Pesa locally (ngrok)

Safaricom must reach your machine to deliver payment results. Tunnel to the
**API host** and rewrite the Host header, because the app rejects unknown hosts:

```bash
ngrok http 8080 --host-header=api.albabook.localhost
```

Put the forwarding address in `.env` and restart the app:

```dotenv
MPESA_CALLBACK_BASE_URL=https://your-subdomain.ngrok-free.app
```

Leave `MPESA_CALLBACK_IPS` empty while tunnelling. Use Daraja sandbox keys
(shortcode `174379`) under Settings → Payments for till payments, and the
`MPESA_BILLING_*` values for subscription payments. ngrok's inspector at
http://127.0.0.1:4040 shows each callback. A free ngrok address changes on
every restart; update `.env` each time, or claim a free static domain.
`cloudflared tunnel --url http://127.0.0.1:8080 --http-host-header api.albabook.localhost`
works the same way.

## Configuration

Everything is read from `.env` and validated at startup: the app refuses to
boot and lists every problem, without printing values. `.env.example`
documents each setting. The groups are:

- **App and hosts:** `APP_KEY` (also the key for stored secrets, so never
  change it silently), `APP_HOST_*`, `TRUSTED_PROXIES`, `SESSION_SECURE_COOKIE`.
- **Docker:** `APP_PORT` (web, default 8080) and `DB_FORWARD_PORT` (MySQL, default 33060).
- **Ops:** `OPS_SCHEDULER_MAX_LAG_SECONDS`, `OPS_BACKUP_MAX_AGE_SECONDS` for `/health`.
- **Database:** runtime user plus an optional separate migration owner
  (`DB_MIGRATE_*`) for least privilege in production.
- **Mail:** `MAIL_TRANSPORT=log` writes to `storage/logs/mail.log` locally.
- **Messaging:** platform SMS / WhatsApp accounts, restricted to
  `SMS_ALLOWED_PREFIXES` / `WHATSAPP_ALLOWED_PREFIXES` (default `+254`).
- **M-Pesa:** `MPESA_CALLBACK_BASE_URL`, `MPESA_CALLBACK_IPS`; AlbaBook's own
  billing app in `MPESA_BILLING_*` (including `MPESA_BILLING_CALLBACK_SECRET`),
  `BILLING_GRACE_DAYS`, `BILLING_LEAD_DAYS`.
- **Custom domains:** `CUSTOM_DOMAINS_ENABLED=false` until the edge can route
  and certify them (see `docs/deployment.md`).

Businesses' own credentials (M-Pesa, SMS, WhatsApp, eTIMS) are entered in
their Settings and stored encrypted, never in `.env`.

## Everyday commands

```bash
composer test                # all suites (unit, integration on MySQL, feature, security, concurrency)
composer test:unit
composer analyse             # PHPStan level 8
composer cs                  # code style check (cs:fix to apply)
npm run build                # rebuild assets after JS / CSS / template changes
npm run watch:css            # rebuild CSS while editing templates
```

Prefix with `docker compose exec app` when using Docker. Integration tests use
the `albabook_test` database (created by `docker/mysql/init.sql`) and are
skipped when no database is reachable locally, but fail in CI
(`TEST_DB_REQUIRED=1`).

### Console

| Command | Does |
| --- | --- |
| `key:generate [--show]` | write a new `APP_KEY` to `.env` (or print it) |
| `migrate`, `migrate:status`, `migrate:fresh` | apply, list, or drop and re-apply migrations (`fresh` is local only) |
| `db:seed` | permissions, role templates, plans, tenant status policies |
| `platform:admin` | create or reset a platform admin |
| `demo:seed` | demo business with staff, services and bookings (local only) |
| `audit:daily [--apply]` | night audit: expire stale requests, mark no-shows, per branch timezone |
| `booking:integrity-check` | verify there are no double bookings or over-allocated resources |
| `lang:check [--strict]` | list missing translations |
| `down [--retry=seconds]`, `up` | maintenance mode on / off |

## Rules worth knowing before writing code

- Read time only from `Clock` and convert only through `DateTimeService`.
  Store instants in UTC; show them in the branch's timezone.
- Money is `BIGINT` minor units with a currency, never floats (D-006). Format
  through `CurrencyFormatter` or the Twig `|money` filter.
- Every query is scoped by `tenant_id`, and dashboard endpoints also check the
  member's branch scope and "own appointments only". Check both on any new
  endpoint that takes an id or uuid.
- Phone numbers go through `PhoneNumber::normalise()` (E.164, Kenyan mobile
  ranges checked) before they are stored or compared.
- PDO uses native prepares: a named placeholder cannot appear twice in one
  statement. `Database::transaction()` refuses nested transactions; pass the
  connection down instead.
- No inline `<script>`, `style=` or `on*=` attributes; the CSP blocks them.
  Wire behaviour with `data-*` attributes (for example `data-confirm` on a form).
- Templates receive explicit data (`include … with {…} only`), never query or decide.
- Applied migrations are immutable: add a new numbered file.
- Every foreign key to `tenants` is `ON DELETE RESTRICT` (D-024).
- Swahili strings in `lang/sw/` are **drafts** and need a professional
  translator before launch (D-017). `php bin/console lang:check --strict`
  must pass before go-live.

## Layout

```text
app/Core          kernel, container, HTTP, routing, middleware contract, database, views
app/Middleware    concrete middleware (security headers, trusted host, CSRF, tenancy…)
app/Modules       feature modules: Api, Appointments, Auth, Availability, Billing, Booking,
                  Branches, Customers, Dashboard, Integrations, Marketing, Notifications,
                  Payments, Platform, Products, Reports, Resources, Services, Settings,
                  Staff, System, Tax, Tenants, Till
app/Shared        cross-cutting services (time, money, logging, i18n, security, validation)
app/Tenancy       tenant context, resolvers, membership and access checks (D-019)
bootstrap/        app, container, routes, middleware wiring
config/           configuration read from .env
cron/, workers/   scheduler entry point and long-running workers
database/         migrations 000–030
docker/           PHP, Nginx and MySQL configuration (dev and prod), backup script
docs/             specifications, decisions and deployment guide
lang/             translations (en, sw)
resources/        Twig views, CSS, JS
routes/           one file per host, plus shared.php and webhooks.php
tests/            Unit, Integration (MySQL), Feature, Security, Concurrency
```
