# Production deployment

AlbaBook ships `docker-compose.prod.yml`, `docker/php/Dockerfile.prod` and
`docker/nginx/prod.conf` for production. The development stack
(`docker-compose.yml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`)
is for local work only — it runs plain HTTP on `*.localhost`, includes
Composer and coverage tooling, and must never be deployed.

## Prerequisites

- Docker + Compose plugin on the host.
- A TLS certificate covering **both** `albabook.co.ke` and `*.albabook.co.ke`
  (a wildcard does not cover the bare domain, which serves the marketing
  site) placed in `./tls/` as `fullchain.pem` and `privkey.pem` (nginx
  terminates TLS). DNS for `albabook.co.ke` and `www.albabook.co.ke` must
  point at the server too; `www.` redirects to the bare domain.
- `.env.production` (copy `.env.example` and fill in):

```dotenv
APP_ENV=production
APP_KEY=<32-byte key from php bin/console key:generate>
# Hostnames only — no scheme or port (ConfigValidator rejects them).
APP_HOST_TENANT=app.albabook.co.ke
APP_HOST_BOOKING=book.albabook.co.ke
APP_HOST_API=api.albabook.co.ke
APP_HOST_ADMIN=admin.albabook.co.ke
APP_HOST_MARKETING=albabook.co.ke

DB_DATABASE=albabook
# Runtime user (web + workers): SELECT/INSERT/UPDATE/DELETE only, created by
# docker/mysql/prod-init.sh on first boot.
DB_USERNAME=albabook_app
DB_PASSWORD=<strong>
# Schema owner: used only by `php bin/console migrate` (CLI). Must differ
# from DB_USERNAME. Quotes/backslashes are refused in names and passwords.
DB_MIGRATE_USERNAME=albabook_owner
DB_MIGRATE_PASSWORD=<strong, different>
DB_ROOT_PASSWORD=<strong>

# REQUIRED when nginx sits behind a load balancer / CDN / ingress.
# Comma-separated IPs or CIDRs of the trusted proxies. Without this the app
# ignores X-Forwarded-* and every visitor shares one rate-limit bucket.
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12

MAIL_TRANSPORT=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@albabook.co.ke
MAIL_FROM_NAME=AlbaBook
```

## Bring the stack up

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production up -d --build
docker compose -f docker-compose.prod.yml exec app php bin/console migrate
docker compose -f docker-compose.prod.yml exec app php bin/console platform:admin \
    --email=you@example.com --first-name=You --last-name=Admin --force
```

## Scheduled work

The scheduler dispatches notifications, reminders, outbox/webhook deliveries,
cleanup and the nightly audit. Run it every minute from host cron:

```cron
* * * * * cd /opt/albabook && docker compose -f docker-compose.prod.yml exec -T app php cron/scheduler.php
```

## Backups

`docker/backup.sh` dumps the database through the compose `db` service,
verifies the gzip, and prunes files older than `RETENTION_DAYS` (default 14):

```cron
15 3 * * * cd /opt/albabook && ./docker/backup.sh >> /var/log/albabook-backup.log 2>&1
```

Sync `./backups/` off-site (rsync/rclone/object storage) from a second cron
entry — the script deliberately does not ship data anywhere itself.

On success the script also writes `backup.last_ok_at` into `platform_state`
(best-effort), which `/health` reports under `signals.backups`.

## Health and ops signals

`GET /health` (every host) returns `200 {"status":"ok"}` when the node can
serve traffic — database reachable, all migrations applied, storage
writable — and `503` otherwise, so a load balancer can act on the status
code alone. `degraded` is reported during maintenance mode.

The `signals` section reports freshness of work done *outside* web
requests:

- `signals.scheduler` — last `cron/scheduler.php` run. `stale` after
  `OPS_SCHEDULER_MAX_LAG_SECONDS` (default 600 = 10 minutes).
- `signals.workers` — one entry per supervised `workers/*.php` process
  (`notifications`, `outbox`, `reminders`, `webhooks`, `cleanup`), absent
  entirely when none has ever run. Same staleness window as `scheduler`;
  alert when a worker you supervise is stale or missing.
- `signals.backups` — last successful `docker/backup.sh` marker. `stale`
  after `OPS_BACKUP_MAX_AGE_SECONDS` (default 93600 = 26 hours). `unknown`
  until the first run records one.

Signals never change the HTTP status: a sleeping cron or a missed backup
does not mean the node cannot serve traffic, so they must not make a load
balancer drain it. Alert on them from external monitoring instead (e.g. a
cron check that fails when `signals.scheduler != "fresh"`).

## Trusted proxies and TLS termination

`TrustedHostMiddleware` honours `X-Forwarded-Host`, `X-Forwarded-Proto` and
`X-Forwarded-For` **only** when the direct peer matches `TRUSTED_PROXIES`.

- TLS ends at nginx → `fastcgi_param HTTPS on` is already set; leave
  `TRUSTED_PROXIES` empty.
- TLS ends at a load balancer → set `TRUSTED_PROXIES` to the balancer's
  IP range so the app sees `https` (secure cookies, HSTS behaviour) and the
  real client IP (per-visitor public-booking rate limits).
- If nginx itself is behind a proxy, also uncomment `set_real_ip_from` in
  `docker/nginx/prod.conf` so `REMOTE_ADDR` is the edge address the app
  trusts.

Never set `TRUSTED_PROXIES` to a range that includes arbitrary clients — a
forged `X-Forwarded-For` would then spoof any IP for rate limits and audit.

## Custom booking domains (off by default)

`CUSTOM_DOMAINS_ENABLED=false` keeps tenant custom domains switched off: custom
hosts never resolve, and the dashboard cannot add or activate domains. Turn it
on only once **both** of these exist:

1. **Routing** — `docker/nginx/prod.conf`'s `default_server` returns 400 for
   any unknown host, so a custom domain never reaches PHP. Add a TLS server
   that forwards unknown hosts to `index.php` (the app still rejects any host
   that is not an active, verified `tenant_domains` row).
2. **Certificates** — each custom domain needs its own certificate (for
   example on-demand ACME issuance at the edge, gated on the domain being
   active). Nothing in the app issues certificates; `tls_status` only records
   state.

Unverified claims lapse after `tenancy.domain_claim_ttl_hours` (72 h) and are
pruned by the scheduler, so a business cannot park a domain it does not own.

## Messaging cost controls

The platform-owned SMS/WhatsApp account (`SMS_PROVIDER`, `WHATSAPP_PROVIDER`)
is only used for plans that include `sms_messages` / `whatsapp_messages`, is
capped by those monthly limits, and only messages the prefixes in
`SMS_ALLOWED_PREFIXES` / `WHATSAPP_ALLOWED_PREFIXES` (default `+254`). Re-run
the plan seeder after deploying so every plan lists every feature explicitly.

## M-Pesa STK push

- Each business adds its Daraja app (consumer key/secret, Lipa na M-Pesa
  Online passkey; Buy Goods tills also need the store number) under
  Settings → Payments, then the account must be **active**.
- Safaricom posts results to
  `https://api.albabook.co.ke/webhooks/mpesa/stk/{account}/{secret}` — the
  secret is an HMAC per account, so the URL itself authenticates the call.
  Optionally restrict sources with `MPESA_CALLBACK_IPS` (Safaricom's ranges).
- Lost callbacks are recovered: the till polls with STK Query after ~25 s and
  the scheduler sweeps prompts still pending after 90 s.
- Run `cron/scheduler.php` every minute (it already must be).

## WhatsApp

- **Platform sender (default):** set `WHATSAPP_PROVIDER` and the platform
  credentials, then create the 11 utility templates listed in
  `database/migrations/028_whatsapp_templates.sql` in WhatsApp Manager with
  the exact names and wording (variables `{{1}}…`). Messages are only sent
  once Meta has approved them. With Twilio, replace each `wa_template_name`
  with the Content SID (`HX…`).
- **Own number (Growth/Pro):** the business connects its WhatsApp Cloud or
  Twilio account (Settings → Notifications) and maps its own approved
  templates.
- WhatsApp is **off for customers** until a business chooses "instead of SMS"
  or "alongside SMS".

## AlbaBook subscription billing

- Fill in the `MPESA_BILLING_*` values with **AlbaBook's own** Daraja app
  (separate from the businesses' accounts) and set a long random
  `MPESA_BILLING_CALLBACK_SECRET`. Safaricom posts results to
  `https://api.albabook.co.ke/webhooks/billing/mpesa/{invoice}/{signature}`.
- `cron/scheduler.php` runs the lifecycle every minute: trial reminder
  3 days before `trial_ends_at`, invoices `BILLING_LEAD_DAYS` before the
  period ends, marks the subscription past due at period end, and
  `BILLING_GRACE_DAYS` after period end an unpaid workspace becomes
  read-only (plan features denied) until it pays.
- A trial with no plan chosen expires at `trial_ends_at`. Upgrades are
  prorated and apply once paid; downgrades apply at the next renewal.
- Run one sandbox payment end to end before switching
  `MPESA_BILLING_ENVIRONMENT` to `production`.

## Booking approvals and alerts

- Businesses choose automatic confirmation (default) or manual approval
  under Settings → Booking Rules. Unanswered requests expire after the
  configured window, or once the visit time arrives (scheduler).
- New online bookings and customer cancellations are e-mailed to team
  members who can approve bookings for that branch, and optionally sent
  by SMS to the alert phone in Settings → Notifications.

## KRA eTIMS

- AlbaBook must complete KRA's **Third-Party Integrator certification** in the
  eTIMS sandbox before production; the OSCU payloads in
  `app/Modules/Tax/Etims` follow the published spec and need that validation.
- Each business registers for eTIMS (OSCU) with KRA, then in Settings →
  Payments enters the device serial, branch id and item classification code,
  clicks **Initialize with KRA**, and switches eTIMS on. Service charge must be
  0% and VAT 16/8/0%.
- Invoices are numbered and frozen at checkout and transmitted by
  `cron/scheduler.php` with retries; the receipt shows KRA's signature and
  verification QR once transmitted. Credit notes for refunds are not yet
  implemented.
