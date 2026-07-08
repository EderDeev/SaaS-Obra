#!/usr/bin/env sh
set -eu

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

php artisan storage:link || true

exec php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600
