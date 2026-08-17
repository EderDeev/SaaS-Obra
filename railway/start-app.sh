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

export DB_PROTECT_DESTRUCTIVE="${DB_PROTECT_DESTRUCTIVE:-true}"

php artisan config:clear
php artisan storage:link || true

# Mantem o agendador geral ativo; o RDO executa uma unica vez por dia, as 00:05.
php artisan schedule:work &

php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600 &

# Dois workers APS permitem iniciar o processamento de dois projetos em paralelo.
APS_QUEUE="${AUTODESK_APS_QUEUE:-aps}"
APS_WORKER_TIMEOUT="${AUTODESK_APS_WORKER_TIMEOUT:-900}"
php artisan queue:work database --queue="${APS_QUEUE}" --sleep=1 --tries=1 --timeout="${APS_WORKER_TIMEOUT}" &
php artisan queue:work database --queue="${APS_QUEUE}" --sleep=1 --tries=1 --timeout="${APS_WORKER_TIMEOUT}" &

# OCR/GED roda em processo separado para isolar a fila pesada, mas no mesmo servico
# para continuar acessando o volume local do Railway.
php artisan queue:work database --queue=ged --sleep=3 --tries=1 --timeout="${GED_OCR_WORKER_TIMEOUT:-3600}" &

exec php -d upload_max_filesize=100M \
    -d post_max_size=128M \
    -d memory_limit=512M \
    -d max_execution_time=0 \
    -d max_input_time=0 \
    artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
