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

GUARD_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
# shellcheck disable=SC1091
. "$GUARD_DIR/production-guard.sh"
production_guard

umask 0027

if [ "$(id -u)" = "0" ]; then
    mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache
    if [ -n "${PORTABILITY_BACKUP_DIR:-}" ]; then
        mkdir -p "${PORTABILITY_BACKUP_DIR}/tmp"
        chown -R www-data:www-data "${PORTABILITY_BACKUP_DIR}"
    fi
    chown -R www-data:www-data \
        storage/app/private \
        storage/app/public \
        storage/framework \
        storage/logs
    exec setpriv --reuid=www-data --regid=www-data --init-groups --inh-caps=-all "$@"
fi

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

exec "$@"
