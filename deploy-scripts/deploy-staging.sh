#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

fail() {
  printf '[deploy-staging] ERROR: %s\n' "$1" >&2
  exit 1
}

[[ -f artisan && -f .env ]] || fail "Se requieren artisan y .env en el release."
[[ "${CONFIRM_STAGING_DEPLOY:-}" == "YES" ]] || fail "Define CONFIRM_STAGING_DEPLOY=YES para continuar."
[[ "${CONFIRM_STAGING_RESOURCES:-}" == "YES" ]] || fail "Confirma que DB, Redis y storage son exclusivos de staging con CONFIRM_STAGING_RESOURCES=YES."

env_value() {
  local key="$1"
  local value
  value="$(grep -E "^${key}=" .env | tail -n 1 | cut -d= -f2- || true)"
  value="${value#\"}"
  value="${value%\"}"
  value="${value#\'}"
  value="${value%\'}"
  printf '%s' "$value"
}

[[ "$(env_value APP_ENV)" == "production" ]] || fail "APP_ENV debe ser production para simular el runtime real."
[[ "$(env_value APP_DEBUG)" == "false" ]] || fail "APP_DEBUG debe ser false."
[[ "$(env_value SESSION_DRIVER)" == "redis" ]] || fail "SESSION_DRIVER debe ser redis."
[[ "$(env_value CACHE_STORE)" == "redis" ]] || fail "CACHE_STORE debe ser redis."
[[ "$(env_value QUEUE_CONNECTION)" == "redis" ]] || fail "QUEUE_CONNECTION debe ser redis."
[[ "$(env_value SCALE_POST_SALE_QUEUE)" == "true" ]] || fail "SCALE_POST_SALE_QUEUE debe ser true."
[[ "$(env_value SCALE_TARGET_CONCURRENT_USERS)" -ge 10000 ]] || fail "SCALE_TARGET_CONCURRENT_USERS debe ser al menos 10000."
[[ "$(env_value DB_DATABASE)" == *_staging ]] || fail "DB_DATABASE debe terminar en _staging; no se ejecutarán migraciones en otra base."
APP_HOST="$(php -r '$host = parse_url($argv[1], PHP_URL_HOST); echo is_string($host) ? $host : "";' "$(env_value APP_URL)")"
[[ "$(env_value APP_URL)" == https://* && "$APP_HOST" == *staging* ]] || fail "APP_URL debe usar HTTPS y un hostname que contenga 'staging'."
[[ "$(env_value SESSION_COOKIE)" == *staging* ]] || fail "SESSION_COOKIE debe ser exclusiva de staging."
[[ "$(env_value CACHE_PREFIX)" == *staging* ]] || fail "CACHE_PREFIX debe ser exclusivo de staging."
[[ "$(env_value APP_KEY)" == base64:* && "$(env_value APP_KEY)" != *GENERATE* ]] || fail "Genera una APP_KEY única para staging."
[[ -n "$(env_value DB_HOST)" && "$(env_value DB_HOST)" != *'<'* && "$(env_value DB_HOST)" != *'>'* ]] || fail "Configura el endpoint privado de la base staging."
[[ -n "$(env_value REDIS_HOST)" && "$(env_value REDIS_HOST)" != *'<'* && "$(env_value REDIS_HOST)" != *'>'* ]] || fail "Configura el endpoint privado de Redis staging."
[[ -n "$(env_value DB_USERNAME)" && "$(env_value DB_USERNAME)" != *'<'* && "$(env_value DB_USERNAME)" != *'>'* ]] || fail "Configura el usuario exclusivo de la base staging."
[[ -n "$(env_value DB_PASSWORD)" && "$(env_value DB_PASSWORD)" != *'<'* && "$(env_value DB_PASSWORD)" != *'>'* ]] || fail "Configura la contraseña real y exclusiva de la base staging."
[[ -n "$(env_value REDIS_PASSWORD)" && "$(env_value REDIS_PASSWORD)" != *'<'* && "$(env_value REDIS_PASSWORD)" != *'>'* ]] || fail "Configura la contraseña real y exclusiva de Redis staging."

# No permitir variables del proceso que sustituyan la configuración validada.
for key in APP_ENV APP_DEBUG APP_KEY APP_URL DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_URL SESSION_DRIVER SESSION_COOKIE SESSION_DOMAIN SESSION_SECURE_COOKIE CACHE_STORE CACHE_PREFIX QUEUE_CONNECTION REDIS_HOST REDIS_USERNAME REDIS_PASSWORD REDIS_PORT REDIS_DB REDIS_CACHE_DB REDIS_PREFIX REDIS_URL SCALE_POST_SALE_QUEUE SCALE_TARGET_CONCURRENT_USERS; do
  if printenv "$key" >/dev/null; then
    [[ "$(printenv "$key")" == "$(env_value "$key")" ]] || fail "$key exportado difiere del .env."
  fi
done

printf '[deploy-staging] Ejecuta preflight, dependencias y migraciones para %s\n' "$(env_value APP_URL)"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan config:clear
php artisan security:preflight --strict
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
php artisan queue:failed

printf '[deploy-staging] Deploy completado. Verifica workers, logs y health checks.\n'
