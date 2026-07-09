#!/usr/bin/env sh
set -eu

if [ -z "${DB_URL:-}" ] && [ -z "${DATABASE_URL:-}" ]; then
    echo "DB_URL nao configurado. Adicione PostgreSQL no Railway e configure DB_URL=\${{Postgres.DATABASE_URL}} no servico da aplicacao."
    exit 1
fi

if [ -z "${DB_URL:-}" ] && [ -n "${DATABASE_URL:-}" ]; then
    export DB_URL="${DATABASE_URL}"
fi

export DB_CONNECTION="${DB_CONNECTION:-pgsql}"

php artisan config:clear
php artisan migrate --force

if [ "${RAILWAY_RUN_SEEDER:-false}" = "true" ] && [ "${RAILWAY_ALLOW_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
elif [ "${RAILWAY_RUN_SEEDER:-false}" = "true" ]; then
    echo "RAILWAY_RUN_SEEDER=true ignorado. Defina RAILWAY_ALLOW_SEEDER=true para confirmar seed em deploy."
fi
