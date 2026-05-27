#!/usr/bin/env sh
set -eu

php artisan migrate --force

if [ "${RAILWAY_RUN_SEEDER:-false}" = "true" ]; then
    php artisan db:seed --force
fi
