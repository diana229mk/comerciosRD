#!/usr/bin/env bash
# Rollback de CÓDIGO al tag de imagen anterior registrado por deploy-do.sh.
# Nunca revierte el esquema MySQL: si el despliegue fallido aplicó migraciones,
# se niega y remite al procedimiento manual de docs/digitalocean.md.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${COMERCIOS_ENV_FILE:-/etc/comerciosrd/production.env}"
SECRETS_DIR="${COMERCIOS_SECRETS_DIR:-/etc/comerciosrd}"
STATE_DIR="${SECRETS_DIR}/deploy-state"

if [[ "${CONFIRM_DIGITALOCEAN_ROLLBACK:-}" != "YES" ]]; then
  echo "Rechazado. Exporta CONFIRM_DIGITALOCEAN_ROLLBACK=YES para volver a la imagen anterior." >&2
  exit 1
fi

current="$(cat "${STATE_DIR}/current" 2>/dev/null || true)"
target="${ROLLBACK_TO:-$(cat "${STATE_DIR}/previous" 2>/dev/null || true)}"
if [[ -z "$target" ]]; then
  echo "No hay tag anterior registrado en ${STATE_DIR}/previous." >&2
  exit 1
fi
if [[ ! "$target" =~ ^[0-9a-f]{7,40}$ ]]; then
  echo "Tag inválido: ${target}" >&2
  exit 1
fi

# El intento fallido puede no haber llegado a escribir 'current': revisa todos los
# registros posteriores al tag destino.
changed=0
for marker in "${STATE_DIR}"/schema-changed-*; do
  [[ -f "$marker" ]] || continue
  tag="${marker##*/schema-changed-}"
  [[ "$tag" == "$target" ]] && continue
  if [[ "$marker" -nt "${STATE_DIR}/schema-changed-${target}" || ! -f "${STATE_DIR}/schema-changed-${target}" ]] \
    && [[ "$(cat "$marker")" == "1" ]]; then
    changed=1
    echo "El despliegue ${tag} aplicó migraciones." >&2
  fi
done
if [[ "$changed" -ne 0 && "${ROLLBACK_SCHEMA_REVIEWED:-}" != "YES" ]]; then
  echo "Rollback automático rechazado: el código ${target} puede no ser compatible con el esquema actual." >&2
  echo "Sigue 'Fallo de despliegue con migraciones' en docs/digitalocean.md." >&2
  echo "Solo si se verificó que las migraciones son aditivas y compatibles, repite con ROLLBACK_SCHEMA_REVIEWED=YES." >&2
  exit 1
fi

for image in "comercios-rd-php:${target}" "comercios-rd-nginx:${target}"; do
  if ! docker image inspect "$image" >/dev/null 2>&1; then
    echo "No existe la imagen ${image} en este nodo." >&2
    exit 1
  fi
done

export COMERCIOS_ENV_FILE="$ENV_FILE" COMERCIOS_SECRETS_DIR="$SECRETS_DIR" COMERCIOS_IMAGE_TAG="$target"
compose=(docker compose -f "${ROOT_DIR}/compose.production.yaml" --env-file "$ENV_FILE")
env_value() {
  grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d "\"'" || true
}
role="$(env_value COMERCIOS_NODE_ROLE)"
app_replicas="$(env_value APP_REPLICAS)"
worker_replicas="$(env_value WORKER_REPLICAS)"
services=(app worker nginx)
scale=(--scale "app=${app_replicas:-3}" --scale "worker=${worker_replicas:-2}" --scale nginx=1)
if [[ "${role:-primary}" == "primary" ]]; then
  compose+=(--profile primary)
  services+=(scheduler)
  scale+=(--scale scheduler=1)
fi

echo "Volviendo de ${current:-<desconocido>} a ${target} (sin build, sin migraciones)."
"${compose[@]}" up -d --no-build --no-deps "${scale[@]}" "${services[@]}"

ready=0
for _ in $(seq 1 30); do
  if "${compose[@]}" exec -T nginx wget -q -O /dev/null http://127.0.0.1:8080/up; then
    ready=1
    break
  fi
  sleep 2
done
if [[ "$ready" -ne 1 ]]; then
  echo "La versión ${target} no respondió en /up. Escala a la recuperación manual." >&2
  exit 1
fi

[[ -n "$current" ]] && printf '%s\n' "$current" > "${STATE_DIR}/rolled-back-from"
printf '%s\n' "$target" > "${STATE_DIR}/current"
echo "Rollback de código completado: ${target} en línea."
