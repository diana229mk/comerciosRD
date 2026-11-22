# ComerciosRD en DigitalOcean

Este paquete deja el primer nodo listo para crecer. No abre la cuenta de DigitalOcean ni copia datos reales. El Compose local (`compose.yaml`) sigue siendo solo de desarrollo.

## Qué queda cubierto

- La app no guarda sesiones en disco. Sesión, caché, colas y modo mantenimiento usan Redis, así que se pueden sumar procesos PHP en el mismo Droplet.
- Web, worker y scheduler son procesos distintos. El worker se escala. El scheduler queda en uno; las tareas usan `onOneServer` por si alguien arranca otro.
- Nginx re-resuelve el servicio `app`, así que `--scale app=2` en el mismo host reparte FastCGI sin reconstruir la imagen.
- MySQL de producción exige CA y hostname. El contenedor no arranca contra `comercios_rd_storage_test`, `localhost` ni una `APP_KEY` de ejemplo.
- `TRUSTED_PROXIES` vacío ignora `X-Forwarded-*`. Un cliente no puede fingir HTTPS ni su IP. En este borde se confía solo porque Caddy y Nginx no están publicados hacia afuera.
- PHP-FPM sale de `PHP_FPM_MAX_CHILDREN`. Subir el Droplet no exige una imagen nueva.
- El disco público puede pasar a Spaces con `PUBLIC_FILESYSTEM_DRIVER=s3` sin cambiar código. Hasta entonces sigue en local.

## Qué no autoriza este documento

El estado de cada requisito vive en [DEPLOYMENT_FINAL_CHECKLIST.md](../DEPLOYMENT_FINAL_CHECKLIST.md). La contención de crédito, los pagos atómicos y la sucursal inactiva ya tienen corrección y pruebas (F2, DATA-02, SEC-02). Siguen abiertos el contrato de `branch_id` NULL (SEC-03) y el legado de ventas sin evento (INF-05) si se migran datos antiguos.

No despliegues datos de clientes hasta que staging pase el checklist y el responsable lo autorice por escrito.

## Tamaño del primer nodo

Un Droplet de 2 vCPU y 4 GB en la misma región y VPC que Managed MySQL 8.4. Redis va en el Droplet, sin puerto público. Eso aguanta el arranque (`SCALE_TARGET_CONCURRENT_USERS=200`), no la meta de 10.000.

Cuando haga falta más CPU en la misma máquina: sube `APP_REPLICAS`, `WORKER_REPLICAS` y `PHP_FPM_MAX_CHILDREN`, y vuelve a correr el deploy.

## Cómo crecer a un segundo servidor

Hazlo en este orden. Saltarse un paso parte las sesiones o las fotos.

1. Pasa Redis a Managed Caching en la misma VPC. En el env: `REDIS_HOST` con el hostname privado, `REDIS_PORT=25061`, `REDIS_SCHEME=tls` y `REDIS_SSL_CA` apuntando a la CA. El código verifica el certificado; no hay un modo que lo apague.
2. Crea dos Spaces privados y distintos: uno para documentos y otro para fotos. `PRIVATE_FILESYSTEM_DRIVER=s3`, `PUBLIC_FILESYSTEM_DRIVER=s3`. Restaura un objeto de prueba. Solo después pon `PORTABILITY_OBJECT_BACKUP_VERIFIED=true`.
3. Quita Caddy del nodo y pon un Load Balancer con el certificado. Cambia `TRUSTED_PROXIES` a las IP privadas del balanceador.
4. El segundo Droplet usa `COMERCIOS_NODE_ROLE=replica`. El deploy rechaza la réplica si los discos siguen en local o si el backup de objetos no está marcado. No corre migraciones ni scheduler.

La base sigue siendo una. Más PHP no arregla un MySQL chico. Mide conexiones y consultas lentas antes de comprar réplicas de base.

## Recursos que creas en DigitalOcean

- VPC de la región. Droplet y base dentro de esa VPC.
- Managed MySQL 8.4. Sin acceso root. Backups automáticos del plan. Trusted sources: solo el Droplet.
- Base `comercios_rd` y tres cuentas, nunca `doadmin` desde la app (ver "Cuentas MySQL").
- CA descargada del panel a `/etc/comerciosrd/mysql-ca.pem`.
- Firewall del Droplet: 22 desde tu IP, 80 y 443 desde Internet. No abras 25060, 3306, 6379, 9000 ni 8080.
- DNS: un A de `APP_DOMAIN` a la IP pública del Droplet.
- Spaces queda para el segundo paso, no para el primero. El backup diario de la app escribe en un volumen del Droplet y Managed MySQL guarda el suyo. Copia ese volumen fuera del Droplet antes de tratarlo como respaldo de verdad.

Usa el hostname **privado** de la base. Tiene que coincidir con el certificado. No pongas la IP en `DB_HOST`.

## En el Droplet

```bash
CONFIRM_DROPLET_BOOTSTRAP=YES bash deploy/digitalocean/bootstrap-droplet.sh
git clone https://github.com/diana229mk/comerciosRD.git /opt/comerciosrd
bash /opt/comerciosrd/deploy-scripts/init-do-env.sh
```

`init-do-env.sh` genera, una sola vez y con modo 0600:

| Archivo | Lo carga | Contenido |
|---|---|---|
| `production.env` | app, worker, scheduler, migrate | `APP_KEY`, `REDIS_PASSWORD`, `DB_PASSWORD` de `comercios_app` |
| `migrator.env` | solo `migrate` | `DB_USERNAME`/`DB_PASSWORD` de `comercios_migrator` |
| `backup.env` | solo `scheduler` | `PORTABILITY_DB_USERNAME`/`PASSWORD` de `comercios_backup` |
| `mysql-accounts.sql` | tú, una vez, como `doadmin` | `CREATE USER` y `GRANT` de las tres cuentas |

Edita `APP_URL`, `APP_DOMAIN`, `ACME_EMAIL` y `DB_HOST` en `production.env`. Copia la CA a `/etc/comerciosrd/mysql-ca.pem`.

### Cuentas MySQL

| Cuenta | Privilegios sobre la base | Uso |
|---|---|---|
| `comercios_app` | `SELECT, INSERT, UPDATE, DELETE` | app, worker, scheduler |
| `comercios_migrator` | DML + DDL de la base, nada global | solo `php artisan migrate` |
| `comercios_backup` | `SELECT, SHOW VIEW, TRIGGER, EVENT, LOCK TABLES` | `portability:backup` |

Aplica el SQL generado y bórralo:

```bash
mysql --ssl-mode=VERIFY_IDENTITY --ssl-ca=/etc/comerciosrd/mysql-ca.pem \
  -h <host-privado> -P 25060 -u doadmin -p < /etc/comerciosrd/mysql-accounts.sql
shred -u /etc/comerciosrd/mysql-accounts.sql
```

El production guard aborta el contenedor (exit 78) si la app usa una cuenta de migración, backup o `doadmin`, si `migrate` arranca sin `migrator.env`, o si el scheduler no tiene `backup.env`.

```bash
cd /opt/comerciosrd
CONFIRM_DIGITALOCEAN_DEPLOY=YES CONFIRM_PRODUCTION_MIGRATE=YES \
  bash deploy-scripts/deploy-do.sh
```

El script exige un checkout sin cambios y etiqueta las imágenes con el commit (`comercios-rd-php:<sha>`). Construye, corre `security:preflight --strict`, hace un `portability:backup` con la cuenta de backup, migra con la cuenta del migrador, registra si el esquema cambió y espera `/up`. El estado queda en `/etc/comerciosrd/deploy-state/` (`current`, `previous`, `schema-changed-<sha>`). No borres imágenes anteriores: son el rollback. Después:

```bash
docker compose -f compose.production.yaml --env-file /etc/comerciosrd/production.env \
  --profile primary exec app php artisan make:master-admin
```

El panel del comercio queda en `https://APP_DOMAIN/admin`. El master, en `/master`.

## Seguridad que el nodo exige

- `APP_DEBUG=false`, `APP_ENV=production`, e-CF apagado.
- 2FA obligatorio para owner, admin y master.
- Cookies de sesión cifradas y solo por HTTPS.
- MySQL con CA. `DB_URL` vacío, porque una URL pisaría esa CA.
- Redis con clave de al menos 16 caracteres, sin espacios ni `#`.
- Archivos privados fuera de `public/`. Nginx solo publica fotos de producto.
- El contenedor arranca como root el tiempo justo para ordenar los volúmenes y sigue como `www-data`.

`php artisan security:preflight --strict` corre dentro del contenedor antes de migrar. Si falla, no hay deploy.

## Fallo de despliegue y rollback

El rollback de código y el de esquema son cosas distintas. Una migración MySQL no se revierte sola. `migrate:rollback`, `reset`, `refresh`, `fresh` y `db:wipe` se rechazan contra una base llamada `comercios_rd`; con otro nombre no los uses: el `down()` de una migración puede borrar datos y el DDL de MySQL no es transaccional.

**1. El deploy falló antes de migrar** (build, preflight o backup). Los contenedores en línea no cambiaron. Corrige y repite el deploy.

**2. Migró, pero la versión nueva no responde, o responde mal.**

```bash
CONFIRM_DIGITALOCEAN_ROLLBACK=YES bash deploy-scripts/rollback-do.sh
```

El script vuelve a la imagen de `deploy-state/previous` sin build ni migraciones. Si algún despliegue posterior a ese tag aplicó migraciones, **se niega**. En ese caso:

1. Pon la app en mantenimiento: `docker compose ... exec app php artisan down`.
2. Revisa las migraciones aplicadas (`migrate:status`). Si todas son aditivas (tablas o columnas nuevas con default, índices), el código anterior suele convivir con ellas: repite con `ROLLBACK_SCHEMA_REVIEWED=YES`.
3. Si alguna migración renombró, borró o transformó datos, no hagas rollback de código. Corrige hacia adelante con un commit nuevo, o restaura el backup previo (paso 3).

**3. Restaurar datos.** Último recurso, con autorización expresa. Restaura el backup previo al deploy en una base **nueva** (`portability:restore` se niega a pisar `DB_DATABASE`), o el punto en el tiempo de Managed MySQL en un clúster nuevo. Verifica, y cambia `DB_HOST`/`DB_DATABASE` en el env. Las ventas hechas entre el backup y el fallo se pierden: anota la ventana.

Tras cualquier rollback: `docker compose ... ps`, `/up`, logs del worker (`docker compose ... logs --since 10m worker`) y una venta de prueba.

## Dependencias externas antes de staging

- Cuenta de DigitalOcean con facturación.
- Dos entornos separados: staging (Droplet + Managed MySQL propios) y producción.
- Dominio y acceso a su DNS (`APP_DOMAIN` y un subdominio de staging).
- Correo para ACME y un proveedor SMTP para notificaciones y restablecimiento de claves.
- IP fija o rango de administración para el puerto 22.
- Destino de backups fuera del Droplet (Spaces con cifrado) y la copia externa del respaldo actual.

## Monitoreo y alertas

- Al crear el Droplet, activa **Monitoring** (agente de métricas) y crea políticas de alerta de CPU > 80 %, memoria > 85 % y disco > 80 % durante 5 minutos.
- Crea un **Uptime check** HTTPS sobre `https://APP_DOMAIN/up` con alerta por correo.
- En el Droplet, el chequeo local revisa `/up`, la salud de cada contenedor, el disco y que exista un backup de menos de 26 horas:

```bash
echo 'OPS_ALERT_WEBHOOK=https://hooks.example/...' > /etc/comerciosrd/ops.env   # opcional, 0600
echo '*/5 * * * * root set -a; . /etc/comerciosrd/ops.env 2>/dev/null; /opt/comerciosrd/deploy-scripts/ops-check.sh >/dev/null' \
  > /etc/cron.d/comerciosrd-ops
```

Los fallos quedan en syslog (`journalctl -t comerciosrd-ops`) y, si hay webhook, llegan como `{"text": "..."}`.
