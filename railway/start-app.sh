#!/usr/bin/env sh
set -eu

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ -n "${RAILWAY_VOLUME_MOUNT_PATH:-}" ]; then
    mkdir -p "${RAILWAY_VOLUME_MOUNT_PATH}"
    echo "Railway volume mounted at ${RAILWAY_VOLUME_MOUNT_PATH}"
fi

php artisan storage:link || true

exec php -d upload_max_filesize=64M \
    -d post_max_size=64M \
    -d memory_limit=512M \
    artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
