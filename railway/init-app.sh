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
export DB_PROTECT_DESTRUCTIVE="${DB_PROTECT_DESTRUCTIVE:-true}"

php artisan config:clear
php artisan migrate --force

if [ "${RAILWAY_RUN_SEEDER:-false}" = "true" ]; then
    echo "RAILWAY_RUN_SEEDER=true ignorado: seed e bloqueado em producao para proteger os dados."
fi
