#!/bin/sh
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY must be set before the application can start." >&2
    exit 1
fi

if [ -z "${JWT_SECRET:-}" ]; then
    if [ "${APP_ENV:-production}" = "production" ]; then
        echo "JWT_SECRET must be set in production." >&2
        exit 1
    fi

    export JWT_SECRET="$APP_KEY"
    echo "JWT_SECRET is unset; using APP_KEY for this non-production container." >&2
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${APP_ENV:-production}" = "production" ]; then
    php artisan config:cache
else
    php artisan config:clear
fi

touch /tmp/limitra-ready

exec "$@"
