#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SKIP_NPM="${SKIP_NPM:-0}"
SKIP_DOWN="${SKIP_DOWN:-0}"
SKIP_SECURITY_PREFLIGHT="${SKIP_SECURITY_PREFLIGHT:-0}"

log() {
  printf '[deploy-prod] %s\n' "$1"
}

run() {
  log "$*"
  "$@"
}

if [[ ! -f artisan ]]; then
  echo "Error: artisan not found. Run this script inside the ComerciosRD project." >&2
  exit 1
fi

APP_ENV_VALUE="$(php -r "echo getenv('APP_ENV') ?: '';" 2>/dev/null || true)"
if [[ -z "$APP_ENV_VALUE" && -f .env ]]; then
  APP_ENV_VALUE="$(grep -E '^APP_ENV=' .env | head -n1 | cut -d'=' -f2- || true)"
fi

if [[ "${APP_ENV_VALUE:-}" != "production" ]]; then
  echo "Error: APP_ENV is '${APP_ENV_VALUE:-undefined}'. Expected 'production'." >&2
  echo "Set APP_ENV=production before running this script." >&2
  exit 1
fi

log "Starting production deploy in: $ROOT_DIR"

if [[ "$SKIP_SECURITY_PREFLIGHT" != "1" ]]; then
  run php artisan security:preflight --strict
else
  log "Skipping security preflight (SKIP_SECURITY_PREFLIGHT=1)"
fi

run composer install --no-dev --prefer-dist --optimize-autoloader

if [[ "$SKIP_NPM" != "1" ]]; then
  run npm ci
  run npm run build
else
  log "Skipping npm build (SKIP_NPM=1)"
fi

run php artisan storage:link || true

if [[ "$SKIP_DOWN" != "1" ]]; then
  run php artisan down --message="Deploy in progress"
else
  log "Skipping maintenance mode down (SKIP_DOWN=1)"
fi

trap 'php artisan up >/dev/null 2>&1 || true' EXIT

run php artisan migrate --force
run php artisan config:cache
run php artisan route:cache
run php artisan view:cache
run php artisan queue:restart

run php artisan up
trap - EXIT

log "Deploy completed successfully."
log "Post-checks recommended:"
log "  php artisan queue:failed"
log "  tail -n 200 storage/logs/laravel.log"
