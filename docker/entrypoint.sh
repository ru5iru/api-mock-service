#!/bin/sh
set -eu

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ "${APP_ENV:-production}" = "production" ]; then
    case "${APP_KEY:-}" in
        ""|"base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="|"base64:replace-with-32-random-bytes-in-base64")
            echo "Refusing to start in production: set a unique APP_KEY." >&2
            exit 1
            ;;
    esac

    case "${DB_PASSWORD:-}" in
        ""|"mock_api"|"replace-this-too")
            echo "Refusing to start in production: set a strong DB_PASSWORD." >&2
            exit 1
            ;;
    esac

    case "${MOCK_DASHBOARD_AUTH_ENABLED:-true}" in
        true|1)
            case "${MOCK_DASHBOARD_PASSWORD:-}" in
                ""|"change-this-too")
                    echo "Refusing to start in production: set a strong MOCK_DASHBOARD_PASSWORD." >&2
                    exit 1
                    ;;
            esac
            ;;
    esac
elif [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY
fi

php artisan config:clear --no-interaction

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

exec "$@"
