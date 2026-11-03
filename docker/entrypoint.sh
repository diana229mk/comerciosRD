#!/bin/sh
set -eu

if [ "${DOCKER_LOCAL_ISOLATION:-0}" = "1" ]; then
    if [ "${APP_ENV:-}" != "local" ] \
        || [ "${DB_CONNECTION:-}" != "mysql" ] \
        || [ "${DB_HOST:-}" != "mysql" ] \
        || [ "${DB_PORT:-}" != "3306" ] \
        || [ "${DB_DATABASE:-}" != "comercios_rd_docker_test" ] \
        || [ "${DB_USERNAME:-}" != "comercios_docker_app" ] \
        || [ -n "${DB_URL:-}" ] \
        || [ -n "${DB_SOCKET:-}" ] \
        || [ "${REDIS_HOST:-}" != "redis" ] \
        || [ "${REDIS_PORT:-}" != "6379" ] \
        || [ -n "${REDIS_USERNAME:-}" ] \
        || [ -n "${REDIS_URL:-}" ] \
        || [ "${ECF_ENABLED:-true}" != "false" ]; then
        echo "Docker local isolation check failed; refusing to start." >&2
        exit 78
    fi
fi

umask 0027
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

exec "$@"
