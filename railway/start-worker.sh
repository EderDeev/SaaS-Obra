#!/usr/bin/env sh
set -eu

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

php artisan storage:link || true

php artisan queue:work database --queue=imports,default,maintenance --sleep=3 --tries=1 --timeout=3600 &

APS_QUEUE="${AUTODESK_APS_QUEUE:-aps}"
APS_WORKER_TIMEOUT="${AUTODESK_APS_WORKER_TIMEOUT:-900}"
php artisan queue:work database --queue="${APS_QUEUE}" --sleep=1 --tries=1 --timeout="${APS_WORKER_TIMEOUT}" &
exec php artisan queue:work database --queue="${APS_QUEUE}" --sleep=1 --tries=1 --timeout="${APS_WORKER_TIMEOUT}"
