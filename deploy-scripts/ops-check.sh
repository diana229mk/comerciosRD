#!/usr/bin/env bash
# Chequeo operativo del nodo, pensado para cron cada 5 minutos:
#   */5 * * * * root /opt/comerciosrd/deploy-scripts/ops-check.sh
# Revisa /up, salud de contenedores, disco y antigüedad del último backup.
# Si algo falla: escribe en syslog (tag comerciosrd-ops), avisa a OPS_ALERT_WEBHOOK
# si está definido, y sale con 1. No modifica nada.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="${COMERCIOS_ENV_FILE:-/etc/comerciosrd/production.env}"
SECRETS_DIR="${COMERCIOS_SECRETS_DIR:-/etc/comerciosrd}"
DISK_MAX_PERCENT="${OPS_DISK_MAX_PERCENT:-85}"
BACKUP_MAX_MINUTES="${OPS_BACKUP_MAX_MINUTES:-1560}"

export COMERCIOS_ENV_FILE="$ENV_FILE" COMERCIOS_SECRETS_DIR="$SECRETS_DIR"
export COMERCIOS_IMAGE_TAG="$(cat "${SECRETS_DIR}/deploy-state/current" 2>/dev/null || echo production)"
role="$(grep -E '^COMERCIOS_NODE_ROLE=' "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2 | tr -d "\"'")"
compose=(docker compose -f "${ROOT_DIR}/compose.production.yaml" --env-file "$ENV_FILE")
expected=(app worker nginx)
if [[ "${role:-primary}" == "primary" ]]; then
  compose+=(--profile primary)
  expected+=(scheduler redis caddy)
fi

problems=()

"${compose[@]}" exec -T nginx wget -q -T 5 -O /dev/null http://127.0.0.1:8080/up 2>/dev/null \
  || problems+=("/up no responde")

for service in "${expected[@]}"; do
  ids="$("${compose[@]}" ps -q "$service" 2>/dev/null)"
  if [[ -z "$ids" ]]; then
    problems+=("${service}: sin contenedores")
    continue
  fi
  while read -r id; do
    [[ -n "$id" ]] || continue
    state="$(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$id" 2>/dev/null)"
    case "$state" in
      "running healthy"|"running none") ;;
      *) problems+=("${service} ${id:0:12}: ${state:-desconocido}") ;;
    esac
  done <<< "$ids"
done

used="$(df -P / | awk 'NR==2 {gsub("%","",$5); print $5}')"
if [[ "$used" =~ ^[0-9]+$ ]] && (( used >= DISK_MAX_PERCENT )); then
  problems+=("disco / al ${used}%")
fi

if [[ "${role:-primary}" == "primary" ]]; then
  fresh="$("${compose[@]}" exec -T scheduler find /var/backups/comerciosrd -mindepth 1 -maxdepth 1 -type d \
    ! -name '*.incomplete' ! -name tmp -mmin "-${BACKUP_MAX_MINUTES}" 2>/dev/null | head -1)"
  [[ -n "$fresh" ]] || problems+=("sin backup completo en los últimos ${BACKUP_MAX_MINUTES} minutos")
fi

if (( ${#problems[@]} == 0 )); then
  echo "OPS_CHECK=PASS"
  exit 0
fi

message="ComerciosRD $(hostname): $(IFS='; '; echo "${problems[*]}")"
echo "OPS_CHECK=FAIL"
printf '%s\n' "${problems[@]}"
command -v logger >/dev/null 2>&1 && logger -t comerciosrd-ops -p user.err "$message"
if [[ -n "${OPS_ALERT_WEBHOOK:-}" ]]; then
  payload="$(printf '%s' "$message" | python3 -c 'import json,sys; print(json.dumps({"text": sys.stdin.read()}))')"
  curl -fsS -m 10 -H 'Content-Type: application/json' -d "$payload" "$OPS_ALERT_WEBHOOK" >/dev/null \
    || logger -t comerciosrd-ops -p user.err "no se pudo enviar la alerta al webhook"
fi
exit 1
