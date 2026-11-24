#!/usr/bin/env bash
# AlbaBook production backup (docs/deployment.md).
#
# Dumps the MySQL database inside the compose `db` service to a timestamped,
# gzipped SQL file, verifies the archive, and prunes old backups.
#
#   ./docker/backup.sh                       # default ./backups output
#   BACKUP_DIR=/srv/backups ./docker/backup.sh
#
# Schedule nightly via cron:
#   15 3 * * * /opt/albabook/docker/backup.sh >> /var/log/albabook-backup.log 2>&1
#
# Restore:  gunzip < backups/albabook-XXXX.sql.gz \
#             | docker compose -f docker-compose.prod.yml exec -T db \
#               mysql -u"$DB_USERNAME" -p"$DB_PASSWORD" albabook

set -euo pipefail

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
ENV_FILE="${ENV_FILE:-.env.production}"
BACKUP_DIR="${BACKUP_DIR:-backups}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
DB_NAME="${DB_DATABASE:-albabook}"
DB_USER="${DB_USERNAME:-albabook}"

cd "$(dirname "$0")/.."
mkdir -p "$BACKUP_DIR"

if [ -f "$ENV_FILE" ]; then
    set -a; . "$ENV_FILE"; set +a
    DB_NAME="${DB_DATABASE:-$DB_NAME}"
    DB_USER="${DB_USERNAME:-$DB_USER}"
fi
: "${DB_PASSWORD:?DB_PASSWORD must be set in the environment or $ENV_FILE}"

stamp="$(date -u +%Y%m%d-%H%M%S)"
out="$BACKUP_DIR/albabook-${stamp}.sql.gz"
tmp="${out}.partial"

docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T db \
    mysqldump --single-transaction --quick --routines --triggers \
        --default-character-set=utf8mb4 \
        -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
    | gzip -9 > "$tmp"

# Never leave a half-written dump behind.
gzip -t "$tmp"
mv "$tmp" "$out"
echo "$(date -u +%FT%TZ) wrote $out ($(du -h "$out" | cut -f1))"

# Record the success timestamp where /health can see it: ./backups lives on
# the host while the app runs in a container, so the marker goes into the
# database itself. Best-effort — a marker failure never fails the backup.
docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T db \
    mysql -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e \
    "INSERT INTO platform_state (name, value) VALUES ('backup.last_ok_at', UTC_TIMESTAMP(6))
     ON DUPLICATE KEY UPDATE value = VALUES(value)" 2>/dev/null \
    || echo "$(date -u +%FT%TZ) WARN: platform_state backup marker not written" >&2

find "$BACKUP_DIR" -name 'albabook-*.sql.gz' -mtime "+${RETENTION_DAYS}" -delete

# Offsite copy is intentionally not handled here — sync BACKUP_DIR with your
# object store (rsync, rclone, aws s3 sync) from a second cron line.
