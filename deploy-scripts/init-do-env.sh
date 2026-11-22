#!/usr/bin/env bash
# Crea /etc/comerciosrd/production.env una sola vez, con APP_KEY y Redis nuevos.
# No imprime los secretos y no pisa un archivo que ya existe.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXAMPLE="${ROOT_DIR}/.env.digitalocean.example"
DEST="${COMERCIOS_ENV_FILE:-/etc/comerciosrd/production.env}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Corre este script como root en el Droplet." >&2
  exit 1
fi

if [[ ! -f "$EXAMPLE" ]]; then
  echo "No está ${EXAMPLE}." >&2
  exit 1
fi

install -d -m 0700 "$(dirname "$DEST")"

DIR="$(dirname "$DEST")"
MIGRATOR_ENV="${DIR}/migrator.env"
BACKUP_ENV="${DIR}/backup.env"
ACCOUNTS_SQL="${DIR}/mysql-accounts.sql"

if [[ -f "$DEST" || -f "$MIGRATOR_ENV" || -f "$BACKUP_ENV" ]]; then
  echo "Ya existe ${DEST}, ${MIGRATOR_ENV} o ${BACKUP_ENV}. No modifiqué nada." >&2
  echo "Si falta alguno, créalo a mano (modo 0600) con las credenciales de su cuenta MySQL." >&2
  exit 0
fi

app_key="base64:$(openssl rand -base64 32)"
redis_password="$(openssl rand -hex 32)"
app_db_password="$(openssl rand -hex 24)"
migrator_password="$(openssl rand -hex 24)"
backup_password="$(openssl rand -hex 24)"
db_name="$(grep -E '^DB_DATABASE=' "$EXAMPLE" | cut -d= -f2-)"
app_user="$(grep -E '^DB_USERNAME=' "$EXAMPLE" | cut -d= -f2-)"
migrator_user="comercios_migrator"
backup_user="comercios_backup"
umask 077
tmp="$(mktemp)"

while IFS= read -r line || [[ -n "$line" ]]; do
  case "$line" in
    APP_KEY=*) printf 'APP_KEY="%s"\n' "$app_key" ;;
    REDIS_PASSWORD=*) printf 'REDIS_PASSWORD=%s\n' "$redis_password" ;;
    DB_PASSWORD=*) printf 'DB_PASSWORD=%s\n' "$app_db_password" ;;
    *) printf '%s\n' "$line" ;;
  esac
done < "$EXAMPLE" > "$tmp"
install -m 0600 "$tmp" "$DEST"

# Solo el servicio migrate carga este archivo (sustituye la cuenta de la app).
printf 'DB_USERNAME=%s\nDB_PASSWORD=%s\n' "$migrator_user" "$migrator_password" > "$tmp"
install -m 0600 "$tmp" "$MIGRATOR_ENV"

# Solo el scheduler lo carga: portability:backup usa esta cuenta de lectura.
printf 'PORTABILITY_DB_USERNAME=%s\nPORTABILITY_DB_PASSWORD=%s\n' "$backup_user" "$backup_password" > "$tmp"
install -m 0600 "$tmp" "$BACKUP_ENV"

sed \
  -e "s/__DB__/${db_name}/g" \
  -e "s/__APP_USER__/${app_user}/g" -e "s/__APP_PASSWORD__/${app_db_password}/g" \
  -e "s/__MIGRATOR_USER__/${migrator_user}/g" -e "s/__MIGRATOR_PASSWORD__/${migrator_password}/g" \
  -e "s/__BACKUP_USER__/${backup_user}/g" -e "s/__BACKUP_PASSWORD__/${backup_password}/g" \
  "${ROOT_DIR}/deploy/digitalocean/mysql-accounts.template" > "$tmp"
install -m 0600 "$tmp" "$ACCOUNTS_SQL"
rm -f "$tmp"

echo "Listo: ${DEST}, ${MIGRATOR_ENV}, ${BACKUP_ENV} y ${ACCOUNTS_SQL} (todos 0600)."
echo "Edita APP_URL, APP_DOMAIN, ACME_EMAIL y DB_HOST en ${DEST}."
echo "Aplica ${ACCOUNTS_SQL} una vez como doadmin (ver la cabecera) y bórralo después."
echo "Guarda la CA de Managed MySQL en ${DIR}/mysql-ca.pem (modo 0644)."
echo "Los secretos generados quedaron solo en esos archivos."
