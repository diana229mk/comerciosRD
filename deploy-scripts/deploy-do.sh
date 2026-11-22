#!/usr/bin/env bash
# Despliegue del nodo DigitalOcean. No crea la cuenta ni la base.
# Primary: Redis, Caddy, scheduler, migraciones y la app.
# Réplica: solo app, worker y nginx. Exige discos compartidos ya verificados.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE="${COMERCIOS_ENV_FILE:-/etc/comerciosrd/production.env}"
SECRETS_DIR="${COMERCIOS_SECRETS_DIR:-/etc/comerciosrd}"

if [[ "${CONFIRM_DIGITALOCEAN_DEPLOY:-}" != "YES" ]]; then
  echo "Rechazado. Exporta CONFIRM_DIGITALOCEAN_DEPLOY=YES cuando este archivo apunte al Droplet y a la base de este entorno." >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Falta ${ENV_FILE}. Corre deploy-scripts/init-do-env.sh y edítalo." >&2
  exit 1
fi

if [[ ! -r "${SECRETS_DIR}/mysql-ca.pem" ]]; then
  echo "Falta ${SECRETS_DIR}/mysql-ca.pem" >&2
  exit 1
fi

load_env() {
  local line key value
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    [[ -z "$line" || "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] || {
      echo "Línea inválida en el env: ${line%%=*}" >&2
      exit 1
    }
    key="${line%%=*}"
    value="${line#*=}"
    if [[ "$value" =~ ^\".*\"$ || "$value" =~ ^\'.*\'$ ]]; then
      value="${value:1:${#value}-2}"
    fi
    printf -v "$key" '%s' "$value"
    export "$key"
  done < "$ENV_FILE"
}

load_env

export COMERCIOS_ENV_FILE="$ENV_FILE"
export COMERCIOS_SECRETS_DIR="$SECRETS_DIR"
export DOCKER_PRODUCTION=1

role="${COMERCIOS_NODE_ROLE:-primary}"
# Keep a realistic high-load baseline without overcommitting a single node.
# The defaults are intentionally conservative and can be raised only after staging validation.
app_replicas="${APP_REPLICAS:-3}"
worker_replicas="${WORKER_REPLICAS:-2}"
max_children="${PHP_FPM_MAX_CHILDREN:-32}"

if [[ "$role" != "primary" && "$role" != "replica" ]]; then
  echo "COMERCIOS_NODE_ROLE debe ser primary o replica." >&2
  exit 1
fi

for number in "$app_replicas" "$worker_replicas"; do
  if [[ ! "$number" =~ ^[1-9][0-9]*$ ]] || (( number > 12 )); then
    echo "APP_REPLICAS y WORKER_REPLICAS deben estar entre 1 y 12." >&2
    exit 1
  fi
done

if [[ ! "$max_children" =~ ^[0-9]+$ ]] || (( max_children < 2 || max_children > 80 )); then
  echo "PHP_FPM_MAX_CHILDREN debe estar entre 2 y 80." >&2
  exit 1
fi

if [[ "$role" == "replica" ]]; then
  if [[ "${PUBLIC_FILESYSTEM_DRIVER:-local}" == "local" || "${PRIVATE_FILESYSTEM_DRIVER:-local}" == "local" ]]; then
    echo "La réplica no arranca con discos locales. Primero comparte imágenes y documentos." >&2
    exit 1
  fi
  if [[ "${PORTABILITY_OBJECT_BACKUP_VERIFIED:-false}" != "true" ]]; then
    echo "La réplica exige PORTABILITY_OBJECT_BACKUP_VERIFIED=true después de probar el backup del bucket." >&2
    exit 1
  fi
fi

start_servers="$max_children"
if (( start_servers > 4 )); then
  start_servers=4
fi
min_spare=2
if (( min_spare > max_children )); then
  min_spare="$max_children"
fi
max_spare=8
if (( max_spare > max_children )); then
  max_spare="$max_children"
fi
if (( start_servers < min_spare )); then
  start_servers="$min_spare"
fi

umask 022
sed \
  -e "s/__MAX_CHILDREN__/${max_children}/" \
  -e "s/__START_SERVERS__/${start_servers}/" \
  -e "s/__MIN_SPARE__/${min_spare}/" \
  -e "s/__MAX_SPARE__/${max_spare}/" \
  "${ROOT_DIR}/deploy/php/zz-scale.conf.template" > "${SECRETS_DIR}/zz-scale.conf"
chmod 0644 "${SECRETS_DIR}/zz-scale.conf"

if [[ "$role" == "primary" ]]; then
  cat > "${SECRETS_DIR}/redis.conf" <<EOF
bind 0.0.0.0
protected-mode yes
port 6379
appendonly yes
appendfsync everysec
requirepass ${REDIS_PASSWORD}
dir /data
EOF
  chmod 0644 "${SECRETS_DIR}/redis.conf"
fi

# Imágenes inmutables por commit: la anterior queda disponible para rollback-do.sh.
if ! git -C "$ROOT_DIR" diff --quiet HEAD -- || [[ -n "$(git -C "$ROOT_DIR" status --porcelain --untracked-files=no)" ]]; then
  echo "El checkout tiene cambios sin commit. Despliega solo commits revisados." >&2
  exit 1
fi
COMERCIOS_IMAGE_TAG="$(git -C "$ROOT_DIR" rev-parse --short=12 HEAD)"
export COMERCIOS_IMAGE_TAG
STATE_DIR="${SECRETS_DIR}/deploy-state"
install -d -m 0700 "$STATE_DIR"
previous_tag="$(cat "${STATE_DIR}/current" 2>/dev/null || true)"

if [[ "$role" == "primary" ]]; then
  for extra in migrator.env backup.env; do
    if [[ ! -f "${SECRETS_DIR}/${extra}" ]]; then
      echo "Falta ${SECRETS_DIR}/${extra}. Ver docs/digitalocean.md (cuentas MySQL separadas)." >&2
      exit 1
    fi
    if [[ "$(stat -c %a "${SECRETS_DIR}/${extra}")" != "600" ]]; then
      echo "${SECRETS_DIR}/${extra} debe tener modo 0600." >&2
      exit 1
    fi
  done
fi

compose=(docker compose -f "${ROOT_DIR}/compose.production.yaml" --env-file "$ENV_FILE")
if [[ "$role" == "primary" ]]; then
  compose+=(--profile primary)
fi

echo "Construyendo imágenes ${COMERCIOS_IMAGE_TAG}."
"${compose[@]}" build

echo "Preflight de seguridad."
"${compose[@]}" run --rm --no-deps app php artisan security:preflight --strict

if [[ "$role" == "primary" ]]; then
  if [[ "${CONFIRM_PRODUCTION_MIGRATE:-}" != "YES" ]]; then
    echo "Falta CONFIRM_PRODUCTION_MIGRATE=YES para aplicar migraciones." >&2
    exit 1
  fi
  echo "Levantando Redis."
  "${compose[@]}" up -d redis
  ready=0
  for _ in $(seq 1 30); do
    if "${compose[@]}" exec -T redis redis-cli --no-auth-warning ping 2>/dev/null | grep -q PONG; then
      ready=1
      break
    fi
    sleep 2
  done
  if [[ "$ready" -ne 1 ]]; then
    echo "Redis no respondió." >&2
    exit 1
  fi
  echo "Backup previo a migrar (cuenta de backup, imagen nueva)."
  "${compose[@]}" run --rm --no-deps scheduler php artisan portability:backup
  # En la primera instalación no existe la tabla migrations: cuenta como esquema vacío.
  status_before="$("${compose[@]}" run --rm --no-deps -T app php artisan migrate:status --no-ansi 2>&1 || echo SIN_TABLA_MIGRATIONS)"
  echo "Migrando."
  docker compose -f "${ROOT_DIR}/compose.production.yaml" --env-file "$ENV_FILE" \
    --profile primary --profile migrate run --rm migrate
  status_after="$("${compose[@]}" run --rm --no-deps -T app php artisan migrate:status --no-ansi)"
  schema_changed=0
  [[ "$status_before" == "$status_after" ]] || schema_changed=1
  printf '%s\n' "$schema_changed" > "${STATE_DIR}/schema-changed-${COMERCIOS_IMAGE_TAG}"
fi

echo "Levantando servicios."
if [[ "$role" == "primary" ]]; then
  "${compose[@]}" up -d \
    --scale "app=${app_replicas}" \
    --scale "worker=${worker_replicas}" \
    --scale scheduler=1 \
    --scale redis=1 \
    --scale nginx=1 \
    --scale caddy=1
else
  "${compose[@]}" up -d \
    --scale "app=${app_replicas}" \
    --scale "worker=${worker_replicas}" \
    --scale scheduler=0 \
    --scale nginx=1
fi

ready=0
for _ in $(seq 1 30); do
  if "${compose[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1:8080/up; then
    ready=1
    break
  fi
  sleep 2
done

if [[ "$ready" -ne 1 ]]; then
  echo "La app no respondió en /up. Revisa logs; para volver a ${previous_tag:-<ninguna>} usa deploy-scripts/rollback-do.sh." >&2
  exit 1
fi

if [[ -n "$previous_tag" && "$previous_tag" != "$COMERCIOS_IMAGE_TAG" ]]; then
  printf '%s\n' "$previous_tag" > "${STATE_DIR}/previous"
fi
printf '%s\n' "$COMERCIOS_IMAGE_TAG" > "${STATE_DIR}/current"

echo "Nodo ${role} en línea con ${COMERCIOS_IMAGE_TAG}. Replicas app=${app_replicas} worker=${worker_replicas}."
echo "No borres imágenes anteriores: rollback-do.sh las usa."
if [[ "$role" == "primary" ]]; then
  echo "Crea el master admin con:"
  echo "  docker compose -f compose.production.yaml --env-file ${ENV_FILE} --profile primary exec app php artisan make:master-admin"
fi
