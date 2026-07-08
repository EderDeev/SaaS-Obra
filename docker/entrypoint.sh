#!/usr/bin/env sh
set -eu

mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

php artisan config:clear >/dev/null 2>&1 || true
php artisan storage:link >/dev/null 2>&1 || true

exec "$@"
