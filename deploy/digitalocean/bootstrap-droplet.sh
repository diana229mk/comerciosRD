#!/usr/bin/env bash
# Prepara un Droplet Ubuntu nuevo. No despliega la app ni abre puertos de base de datos.

set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Corre este script como root en el Droplet." >&2
  exit 1
fi

if [[ "${CONFIRM_DROPLET_BOOTSTRAP:-}" != "YES" ]]; then
  echo "Rechazado. Exporta CONFIRM_DROPLET_BOOTSTRAP=YES en el Droplet, no en tu máquina local." >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

if ! command -v docker >/dev/null 2>&1; then
  apt-get update
  apt-get install -y ca-certificates curl
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  # shellcheck disable=SC1091
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

install -d -m 0700 /etc/comerciosrd
install -d -m 0755 /opt/comerciosrd

# Parches de seguridad automáticos del sistema (no actualiza Docker de versión mayor).
apt-get install -y unattended-upgrades
dpkg-reconfigure -f noninteractive unattended-upgrades

# SSH solo con clave. Se exige una clave instalada para no dejar el Droplet sin acceso.
if [[ ! -s /root/.ssh/authorized_keys ]]; then
  echo "AVISO: root no tiene authorized_keys; no se endureció SSH. Añade la clave y repite." >&2
else
  cat > /etc/ssh/sshd_config.d/10-comerciosrd.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PermitEmptyPasswords no
X11Forwarding no
MaxAuthTries 3
EOF
  chmod 0644 /etc/ssh/sshd_config.d/10-comerciosrd.conf
  if sshd -t; then
    systemctl reload ssh 2>/dev/null || systemctl reload sshd
  else
    rm -f /etc/ssh/sshd_config.d/10-comerciosrd.conf
    echo "sshd -t falló; se retiró el endurecimiento SSH." >&2
  fi
fi

echo "Recuerda: Docker publica 80/443 por encima de ufw. El filtro real es el Cloud Firewall de DigitalOcean."
echo "Docker listo. Siguiente, en este Droplet:"
echo "  git clone https://github.com/diana229mk/comerciosRD.git /opt/comerciosrd"
echo "  bash /opt/comerciosrd/deploy-scripts/init-do-env.sh"
echo "  editar /etc/comerciosrd/production.env y copiar mysql-ca.pem"
echo "  CONFIRM_DIGITALOCEAN_DEPLOY=YES CONFIRM_PRODUCTION_MIGRATE=YES bash /opt/comerciosrd/deploy-scripts/deploy-do.sh"
