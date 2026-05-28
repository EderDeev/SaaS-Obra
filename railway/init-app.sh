#!/usr/bin/env sh
set -eu

if [ -z "${DB_URL:-}" ] && [ -z "${DATABASE_URL:-}" ]; then
    echo "DB_URL nao configurado. Adicione PostgreSQL no Railway e configure DB_URL=\${{Postgres.DATABASE_URL}} no servico da aplicacao."
    exit 1
fi

php artisan migrate --force

if [ "${RAILWAY_RUN_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
fi
