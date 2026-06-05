#!/usr/bin/env sh
set -eu

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ -n "${RAILWAY_VOLUME_MOUNT_PATH:-}" ]; then
    mkdir -p "${RAILWAY_VOLUME_MOUNT_PATH}"
    echo "Railway volume mounted at ${RAILWAY_VOLUME_MOUNT_PATH}"
fi

php artisan storage:link || true

exec php -d upload_max_filesize=100M \
    -d post_max_size=128M \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    -d max_input_time=0 \
    artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
