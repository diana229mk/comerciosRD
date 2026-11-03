# Montaje de staging aislado

## Alcance

Este repositorio ahora incluye un ejemplo de entorno y un deploy guardado para
staging. No se aprovisionó infraestructura ni se ejecutaron migraciones: aún
falta elegir el proveedor, el servidor, el hostname y crear recursos vacíos y
exclusivos de staging.

Staging simula runtime de producción (`APP_ENV=production`, `APP_DEBUG=false`)
pero debe usar una URL, claves, base de datos, Redis, bucket, correo, usuarios
y datos completamente distintos. No copiar el `.env`, `APP_KEY`, backups ni
datos de clientes desde producción.

INF-06 introduce TLS MySQL fail-closed en runtime `production`; pasó
72 pruebas/281 aserciones y cuatro checks Oracle en Docker aislado.
No acredita el runtime de staging. Montar su CA en lectura en web,
workers, scheduler y portabilidad, y usar un hostname cubierto por el
certificado, sin reutilizar certificados desechables de pruebas. Véase
[mysql-tls.md](mysql-tls.md). No iniciar staging sin cerrar los bloqueadores
pendientes y aprobar por separado la configuración de sus recursos.

## Recursos que crear al escoger proveedor

- Hostname HTTPS de staging, por ejemplo `staging.tudominio.com`; mantener
  `SESSION_DOMAIN=null` para no compartir cookies con el sitio real.
- Uno o más servidores Linux con Nginx y PHP-FPM, no `php artisan serve`.
- Base MySQL privada, vacía, llamada `comerciosrd_staging` y usuario dedicado
  con permisos solo sobre esa base.
- Redis privado y credenciales exclusivas para staging. Para una separación
  fuerte, usar instancia Redis separada; los números de DB y prefijos por sí
  solos no son aislamiento de seguridad.
- Almacenamiento de archivos independiente, si se requiere S3.
- DNS, certificados TLS, firewall y secretos del sistema de CI/CD.

El tamaño de la primera instancia depende del proveedor y del presupuesto.
Para medir una meta de 10,000 sesiones concurrentes se necesitan al menos dos
réplicas de aplicación, un generador de carga aparte y cuotas/servicios
dimensionados para no saturar antes la DB, Redis o el generador.

## Preparación en el host de staging

1. Desplegar el código por un canal de releases/CI y copiar
   `.env.staging.example` a `.env` **en el servidor**.
2. Sustituir todos los placeholders. Generar una `APP_KEY` nueva únicamente
   para staging (`php artisan key:generate --show`) y conservarla en todas las
   réplicas de staging. No reutilizar nunca la key de producción.
3. Confirmar que DB, Redis, filesystem y correo sean los recursos de staging.
   Mantener los workers bajo Supervisor/systemd y usar la misma configuración
   Redis compartida que los servidores web.
4. Instalar k6 en una máquina generadora separada; preparar usuarios de prueba
   y el pool de sesiones como indica `docs/scale-readiness-10000.md`.
5. Revisar que el respaldo/restore de producción y las credenciales reales no
   sean accesibles desde staging.

## Deploy protegido

Desde el release directory del staging, tras verificar manualmente que `.env`
apunta a los recursos aislados:

```bash
CONFIRM_STAGING_RESOURCES=YES \
CONFIRM_STAGING_DEPLOY=YES \
bash deploy-scripts/deploy-staging.sh
```

El script rechaza ejecutar migraciones a menos que `APP_URL` sea HTTPS con
`staging`, `DB_DATABASE` termine en `_staging`, la cookie y el prefijo de caché
sean propios de staging y sesiones/caché/colas sean Redis. También exige
confirmación explícita del deploy y de que los recursos son aislados.

El script valida que APP_KEY y credenciales ya no sean placeholders, limpia
config cache antes del preflight y evita variables exportadas que apunten a
otra base. No es un aprovisionador de cloud ni una prueba de que los recursos
sean realmente aislados; confirma el endpoint y permisos en el panel del
proveedor antes de aceptarlo. No se debe correr en el entorno local o de
producción.

Después del deploy, iniciar/supervisar workers:

```bash
php artisan queue:work redis --queue=sales,reports,default --sleep=3 --tries=3 --timeout=90 --max-time=3600 --memory=256
```

Las colas deben coincidir con `SCALE_QUEUE_SALES`, `SCALE_QUEUE_REPORTS` y
`REDIS_QUEUE` de los productores, incluso con nombres personalizados.
Usar `REDIS_QUEUE_RETRY_AFTER=180`, timeout de 90 s y al menos 120 s de
gracia de apagado. El supervisor debe reiniciar el proceso al salir,
incluidas las salidas normales por `--max-time`.

INF-04 incorpora un outbox SQL y recuperación independiente de Redis mediante
el scheduler. Su validación aislada terminó con 53 pruebas/768 aserciones, cero
fallos; no autoriza este despliegue. Antes de planificar el rollout, revisar
[accounting-outbox.md](accounting-outbox.md), el tratamiento del legado y las
dos tablas nuevas. No ejecutar el migrador completo ni migraciones históricas.

Verificar `php artisan queue:failed`, logs y endpoints de salud. Probar login,
2FA, permisos tenant, búsqueda, venta de prueba, inventario, recibos, backups y
restauración. Para carga de POS, seguir el procedimiento seguro de
`docs/scale-readiness-10000.md`; nunca apuntar el generador a producción.

## Qué falta para continuar

Elegir proveedor y plan, crear la cuenta y recursos de staging, y proporcionar
solo el hostname público (nunca secretos) para completar DNS/TLS y validar el
despliegue. La contratación debe esperar al resultado de la prueba en una
topología equivalente; no se garantiza que un único VPS soporte 10,000
usuarios concurrentes.
