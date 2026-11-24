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
