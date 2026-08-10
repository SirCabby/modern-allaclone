#!/bin/sh
set -e

cd /var/www/html

# nginx needs its temp/cache dirs to exist and be writable by www-data.
mkdir -p /var/lib/nginx/tmp /var/log/nginx /run
chown -R www-data:www-data /var/lib/nginx /var/log/nginx

# The app's own state (sessions, cache, queue, quest index) lives in sqlite so the
# site needs no second MySQL database and never writes to peq.
DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
if [ ! -f "$DB_FILE" ]; then
    echo "[entrypoint] creating sqlite database at $DB_FILE"
    mkdir -p "$(dirname "$DB_FILE")"
    touch "$DB_FILE"
fi
chown -R www-data:www-data storage bootstrap/cache "$(dirname "$DB_FILE")"

# APP_KEY must be stable across restarts or every session is invalidated. Persist a
# generated one next to the sqlite file rather than regenerating it each boot.
KEY_FILE="$(dirname "$DB_FILE")/app_key"
if [ -z "$APP_KEY" ]; then
    if [ ! -f "$KEY_FILE" ]; then
        php artisan key:generate --show > "$KEY_FILE"
        chown www-data:www-data "$KEY_FILE"
    fi
    APP_KEY="$(cat "$KEY_FILE")"
    export APP_KEY
fi

# composer install ran with --no-scripts (no artisan in that stage), so the
# package manifest has to be built here.
php artisan package:discover --ansi

php artisan migrate --force --no-interaction

# Config must be cached AFTER APP_KEY is exported, or the cached config pins an empty key.
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${QUESTS_INDEX_ON_BOOT:-false}" = "true" ]; then
    echo "[entrypoint] indexing quest scripts"
    php artisan quests:index || echo "[entrypoint] quest index failed (continuing)"
fi

# After quests:index -- quest rewards are one of the sources the era index reads.
if [ "${ITEM_ERAS_INDEX_ON_BOOT:-false}" = "true" ]; then
    echo "[entrypoint] indexing item eras"
    php artisan items:index-eras || echo "[entrypoint] item era index failed (continuing)"
fi

# After items:index-eras -- which of two items sharing a name a list means is
# settled by which one the era index reached. Cheap (seconds), and the lists ship
# in the image, so this is not gated behind a flag: a rebuild that changed a list
# file should serve the new list.
echo "[entrypoint] indexing item lists"
php artisan items:index-lists || echo "[entrypoint] item list index failed (continuing)"

exec "$@"
