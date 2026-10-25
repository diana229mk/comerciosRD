#!/usr/bin/env bash
# Crea la base aislada de tests en el MySQL del sistema (3306).
# Requiere root de MySQL vía: sudo bash deploy-scripts/provision-test-db.sh
# No toca comercios_rd.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_NAME=comercios_rd_storage_test
DB_USER=comercios_test
ENV_FILE="$ROOT/.env.testing"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Usa: sudo bash $0"
  echo "Eso pide la clave de Linux, no la de Filament."
  exit 1
fi

if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC1090
  set -a
  # Solo DB_*
  eval "$(grep -E '^DB_(USERNAME|PASSWORD)=' "$ENV_FILE" | sed 's/\r$//')"
  set +a
fi

DB_USER="${DB_USERNAME:-$DB_USER}"
if [[ -z "${DB_PASSWORD:-}" ]]; then
  DB_PASSWORD="$(openssl rand -hex 16)"
fi

mysql --protocol=socket -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES,
      CREATE TEMPORARY TABLES, LOCK TABLES
  ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

umask 077
cat > "$ENV_FILE" <<EOF
APP_ENV=testing

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_URL=
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
EOF
chmod 600 "$ENV_FILE"
chown "${SUDO_USER:-deleon}:${SUDO_USER:-deleon}" "$ENV_FILE"

echo "Lista ${DB_NAME} en 3306. comercios_rd no se tocó."
echo "Luego: sudo -u ${SUDO_USER:-deleon} env APP_ENV=testing php artisan migrate --force -C $ROOT"
