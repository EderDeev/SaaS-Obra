#!/usr/bin/env sh
set -eu

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

if [ -n "${RAILWAY_VOLUME_MOUNT_PATH:-}" ]; then
    mkdir -p "${RAILWAY_VOLUME_MOUNT_PATH}"
    echo "Railway volume mounted at ${RAILWAY_VOLUME_MOUNT_PATH}"
fi

if [ -z "${DB_URL:-}" ] && [ -n "${DATABASE_URL:-}" ]; then
    export DB_URL="${DATABASE_URL}"
fi

if [ -n "${DB_URL:-}" ] || [ -n "${DATABASE_URL:-}" ]; then
    export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
fi

php artisan config:clear
php artisan storage:link || true

# Mantem o agendador do RDO ativo no mesmo servico web.
php artisan schedule:work &

php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600 &

# OCR/GED roda em processo separado para isolar a fila pesada, mas no mesmo servico
# para continuar acessando o volume local do Railway.
php artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout="${GED_OCR_WORKER_TIMEOUT:-3600}" &

exec php -d upload_max_filesize=100M \
    -d post_max_size=128M \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    -d max_input_time=0 \
    artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
