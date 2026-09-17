#!/bin/sh
# Render's free plan gives no shell, so pending schema migrations run at
# container start instead. Each migration is idempotent, so a container that
# restarts on an already-current database does nothing.
#
# A failed migration must not stop the web server: a booting container that
# cannot reach the database yet would crash-loop and take the whole site down,
# whereas a running site only breaks on the pages that need the new schema.
set -e

if [ -f /var/www/html/scripts/migrate_occurrence.php ]; then
    php /var/www/html/scripts/migrate_occurrence.php \
        || echo "entrypoint: reservation_occurrence migration failed; starting web server anyway" >&2
fi

exec docker-php-entrypoint "$@"
