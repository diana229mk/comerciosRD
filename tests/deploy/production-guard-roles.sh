#!/bin/sh
# Contrato de roles de docker/production-guard.sh (app, migrator, backup). Sin Docker.
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CA="$(mktemp)"
trap 'rm -f "$CA"' EXIT
failures=0

check() {
    expected="$1"; shift
    out="$(env -i PATH=/usr/bin:/bin DOCKER_PRODUCTION=1 APP_ENV=production APP_DEBUG=false \
        ECF_ENABLED=false APP_KEY=base64:test APP_URL=https://pos.comercios.test TRUSTED_PROXIES='*' \
        DB_DATABASE=comercios DB_HOST=db-private.test DB_PASSWORD=x MYSQL_ATTR_SSL_CA="$CA" \
        REDIS_HOST=redis REDIS_PASSWORD=abcdefghijklmnop1234 "$@" \
        sh -c ". '$ROOT/docker/production-guard.sh'; production_guard; echo PASS" 2>&1 | tail -1)"
    case "$out" in
        *"$expected"*) echo "ok   $*" ;;
        *) echo "FAIL $* => $out"; failures=$((failures + 1)) ;;
    esac
}

check PASS DB_USERNAME=comercios_app
check "la app no puede" DB_USERNAME=comercios_migrator
check "la app no puede" DB_USERNAME=doadmin
check "falta migrator.env" COMERCIOS_DB_ROLE=migrator COMERCIOS_APP_DB_USERNAME=comercios_app DB_USERNAME=comercios_app
check PASS COMERCIOS_DB_ROLE=migrator COMERCIOS_APP_DB_USERNAME=comercios_app DB_USERNAME=comercios_migrator
check "no debe usar doadmin" COMERCIOS_DB_ROLE=migrator COMERCIOS_APP_DB_USERNAME=comercios_app DB_USERNAME=doadmin
check "falta backup.env" COMERCIOS_DB_ROLE=backup DB_USERNAME=comercios_app
check "solo lectura propia" COMERCIOS_DB_ROLE=backup DB_USERNAME=comercios_app PORTABILITY_DB_USERNAME=comercios_app PORTABILITY_DB_PASSWORD=y
check "PORTABILITY_DB_PASSWORD vac" COMERCIOS_DB_ROLE=backup DB_USERNAME=comercios_app PORTABILITY_DB_USERNAME=comercios_backup
check PASS COMERCIOS_DB_ROLE=backup DB_USERNAME=comercios_app PORTABILITY_DB_USERNAME=comercios_backup PORTABILITY_DB_PASSWORD=y
check "desconocido" COMERCIOS_DB_ROLE=x DB_USERNAME=comercios_app
check "APP_DEBUG" DB_USERNAME=comercios_app APP_DEBUG=true

[ "$failures" -eq 0 ] || { echo "$failures fallos"; exit 1; }
echo "production guard: todos los casos OK"
