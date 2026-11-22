#!/bin/sh

# Falla cerrado cuando el contenedor se marca como producción.
# No hace nada en el Compose local.
production_guard() {
    if [ "${DOCKER_PRODUCTION:-0}" != "1" ]; then
        return 0
    fi

    fail() {
        echo "production guard: $1" >&2
        exit 78
    }

    [ "${APP_ENV:-}" = "production" ] || fail "APP_ENV debe ser production"
    [ "${APP_DEBUG:-}" = "false" ] || fail "APP_DEBUG debe ser false"
    [ "${ECF_ENABLED:-}" = "false" ] || fail "ECF_ENABLED debe ser false"
    [ "${DOCKER_LOCAL_ISOLATION:-0}" != "1" ] || fail "el aislamiento local no puede estar activo"

    case "${APP_KEY:-}" in
        ""|*REPLACE*) fail "APP_KEY vacía o de ejemplo" ;;
    esac

    case "${APP_URL:-}" in
        https://*) ;;
        *) fail "APP_URL debe usar https://" ;;
    esac
    case "${APP_URL:-}" in
        *example.com*|*your-production-domain*|*tudominio.com*) fail "APP_URL sigue siendo de ejemplo" ;;
    esac

    [ -n "${TRUSTED_PROXIES:-}" ] || fail "TRUSTED_PROXIES vacío"

    case "${DB_DATABASE:-}" in
        ""|comercios_rd_storage_test|comercios_rd_docker_test|laravel)
            fail "DB_DATABASE no es una base de producción"
            ;;
    esac

    case "${DB_HOST:-}" in
        ""|mysql|localhost|127.0.0.1|::1|db)
            fail "DB_HOST tiene que ser el hostname privado de Managed MySQL"
            ;;
        *REPLACE*|*CHANGEME*|*"<"*)
            fail "DB_HOST sigue siendo de ejemplo"
            ;;
    esac

    case "${DB_USERNAME:-}" in
        ""|root|comercios_docker_app|comercios_storage_test)
            fail "DB_USERNAME no sirve para producción"
            ;;
    esac

    # Privilegios mínimos: la app no usa la cuenta de DDL; el migrador no usa la de la app.
    case "${COMERCIOS_DB_ROLE:-app}" in
        app)
            case "${DB_USERNAME}" in
                *migrator*|*backup*|doadmin) fail "la app no puede usar la cuenta de migraciones, backup ni doadmin" ;;
            esac
            ;;
        migrator)
            [ -n "${COMERCIOS_APP_DB_USERNAME:-}" ] || fail "migrate necesita COMERCIOS_APP_DB_USERNAME"
            [ "${DB_USERNAME}" != "${COMERCIOS_APP_DB_USERNAME}" ] \
                || fail "migrate usa la cuenta de la app: falta migrator.env con DB_USERNAME/DB_PASSWORD del migrador"
            [ "${DB_USERNAME}" != "doadmin" ] || fail "migrate no debe usar doadmin"
            ;;
        backup)
            [ "${DB_USERNAME}" != "doadmin" ] || fail "el scheduler no debe usar doadmin"
            case "${PORTABILITY_DB_USERNAME:-}" in
                "") fail "falta backup.env con PORTABILITY_DB_USERNAME/PORTABILITY_DB_PASSWORD" ;;
                "${DB_USERNAME}"|doadmin|*migrator*) fail "PORTABILITY_DB_USERNAME debe ser una cuenta de solo lectura propia" ;;
            esac
            [ -n "${PORTABILITY_DB_PASSWORD:-}" ] || fail "PORTABILITY_DB_PASSWORD vacía"
            ;;
        *) fail "COMERCIOS_DB_ROLE desconocido" ;;
    esac

    [ -n "${DB_PASSWORD:-}" ] || fail "DB_PASSWORD vacía"
    case "${DB_PASSWORD:-}" in
        *REPLACE*|*"<"*) fail "DB_PASSWORD sigue siendo de ejemplo" ;;
    esac
    [ -z "${DB_URL:-}" ] || fail "DB_URL no debe usarse: pisa la CA de MySQL"
    [ -z "${DB_SOCKET:-}" ] || fail "DB_SOCKET no admite TLS verificado"

    if [ -z "${MYSQL_ATTR_SSL_CA:-}" ] || [ ! -r "${MYSQL_ATTR_SSL_CA}" ]; then
        fail "MYSQL_ATTR_SSL_CA no apunta a una CA legible"
    fi

    [ -n "${REDIS_HOST:-}" ] || fail "REDIS_HOST vacío"

    case "${REDIS_PASSWORD:-}" in
        ""|*[!A-Za-z0-9_./+=-]*)
            fail "REDIS_PASSWORD vacía o con espacios, comillas o #"
            ;;
    esac
    if [ "${#REDIS_PASSWORD}" -lt 16 ]; then
        fail "REDIS_PASSWORD debe tener al menos 16 caracteres"
    fi

    if [ "${REDIS_SCHEME:-tcp}" = "tls" ]; then
        if [ -z "${REDIS_SSL_CA:-}" ] || [ ! -r "${REDIS_SSL_CA}" ]; then
            fail "REDIS_SCHEME=tls exige REDIS_SSL_CA legible"
        fi
    fi

    if [ "${COMERCIOS_NODE_ROLE:-primary}" = "replica" ]; then
        if [ "${PUBLIC_FILESYSTEM_DRIVER:-local}" = "local" ] || [ "${PRIVATE_FILESYSTEM_DRIVER:-local}" = "local" ]; then
            fail "una réplica no puede usar discos locales"
        fi
        if [ "${PORTABILITY_OBJECT_BACKUP_VERIFIED:-false}" != "true" ]; then
            fail "una réplica exige PORTABILITY_OBJECT_BACKUP_VERIFIED=true"
        fi
    fi
}
