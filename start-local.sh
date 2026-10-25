#!/usr/bin/env bash
# Arranque local de ComerciosRD con chequeo de MySQL y URLs claras.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8000}"

echo "==> ComerciosRD — arranque local"
echo "    Directorio: $ROOT"
echo

# 1) MySQL (sesiones, cache y datos viven en la BD)
if ! command -v mysqladmin >/dev/null 2>&1; then
  echo "ERROR: mysqladmin no está instalado. Instala el cliente MySQL."
  exit 1
fi

if ! mysqladmin ping -h127.0.0.1 --silent 2>/dev/null; then
  echo "ERROR: MySQL no responde en 127.0.0.1:3306."
  echo "       Sin MySQL la app no muestra el panel (session/cache usan la BD)."
  echo
  echo "Intenta:"
  echo "  sudo systemctl start mysql"
  echo "  # o: sudo service mysql start"
  exit 1
fi
echo "OK  MySQL responde"

# 2) PHP / artisan
if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: PHP no está en el PATH."
  exit 1
fi

if [[ ! -f artisan ]]; then
  echo "ERROR: no se encontró artisan en $ROOT"
  exit 1
fi

# 3) Puerto libre (aviso, no aborta si está en uso por otro proceso)
if command -v ss >/dev/null 2>&1; then
  if ss -tln | grep -q ":${PORT} "; then
    echo "AVISO: el puerto $PORT ya está en uso. Si falla el serve, usa: PORT=8001 $0"
  fi
fi

echo
echo "Abre en el navegador:"
echo "  Panel negocio : http://${HOST}:${PORT}/admin/login"
echo "  Panel master  : http://${HOST}:${PORT}/master/login"
echo "  (la raíz / redirige al login de admin)"
echo
echo "Demo con negocios: admin@comerciosrd.local"
echo "Master (sin negocios admin): admin@comerciosrd.com  → solo /master"
echo
echo "Ctrl+C para detener el servidor."
echo

exec php artisan serve --host="$HOST" --port="$PORT"
