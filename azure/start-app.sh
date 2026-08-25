#!/usr/bin/env sh
set -eu

mkdir -p \
    storage/app/public \
    storage/app/private/materialized-files \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

php artisan config:clear
php artisan storage:link >/dev/null 2>&1 || true

# Development runs one replica, so migrations can safely precede the web process.
if [ "${AZURE_RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php artisan schedule:work &
php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600 &
php artisan queue:work database --queue="${AUTODESK_APS_QUEUE:-aps}" --sleep=1 --tries=1 --timeout="${AUTODESK_APS_WORKER_TIMEOUT:-900}" &
php artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout="${GED_OCR_WORKER_TIMEOUT:-3600}" &

exec php \
    -d upload_max_filesize=100M \
    -d post_max_size=128M \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    -d max_input_time=0 \
    artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
