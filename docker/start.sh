#!/bin/sh
set -e

# Caches need the runtime environment, so they are built at boot, not in the image.
php artisan optimize
php artisan filament:optimize

# The database on Railway's private network can take a few seconds to answer.
attempt=1
until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
        echo "migrate failed after $attempt attempts" >&2
        exit 1
    fi
    echo "migrate failed (attempt $attempt), retrying in 3s" >&2
    attempt=$((attempt + 1))
    sleep 3
done

php artisan app:ensure-admin || echo "admin bootstrap skipped" >&2

# Scheduler (abandoned-cart detection, every minute) runs alongside the web server.
php artisan schedule:work &

exec frankenphp run --config /app/docker/Caddyfile
