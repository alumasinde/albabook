#!/bin/bash
# Production MySQL first-boot init (docker-compose.prod.yml). Runs once, when
# the data volume is created.
#
# Least privilege: MYSQL_USER (DB_MIGRATE_USERNAME) already owns the schema
# and is used only by `php bin/console migrate`. Here we create the runtime
# user the web app and workers connect as (DB_USERNAME): data access only —
# no CREATE/ALTER/DROP/GRANT, so a SQL injection or a compromised app server
# cannot change or destroy the schema.
set -euo pipefail

: "${APP_DB_USERNAME:?APP_DB_USERNAME must be set}"
: "${APP_DB_PASSWORD:?APP_DB_PASSWORD must be set}"
: "${MYSQL_DATABASE:?MYSQL_DATABASE must be set}"

if [ "${APP_DB_USERNAME}" = "${MYSQL_USER:-}" ]; then
    echo "prod-init: DB_USERNAME must differ from DB_MIGRATE_USERNAME" >&2
    exit 1
fi

# Identifiers/passwords go through mysql's own quoting; refuse characters that
# would break out of the quoted literals below.
case "${APP_DB_USERNAME}${APP_DB_PASSWORD}${MYSQL_DATABASE}" in
    *"'"*|*'`'*|*'\'*) echo "prod-init: quotes/backslashes are not allowed in DB names or passwords" >&2; exit 1 ;;
esac

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE USER IF NOT EXISTS '${APP_DB_USERNAME}'@'%' IDENTIFIED BY '${APP_DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${APP_DB_USERNAME}'@'%';
FLUSH PRIVILEGES;
SQL

echo "prod-init: runtime user '${APP_DB_USERNAME}' granted DML on ${MYSQL_DATABASE}"
