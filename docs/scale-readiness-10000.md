# Preparación para 10,000 usuarios concurrentes

## Estado actual

La instancia local (`APP_ENV=local`, `APP_DEBUG=true`, `php artisan serve`) no
representa producción y no sirve para validar una meta de 10,000 usuarios.
Esta capacidad no se puede garantizar por configuración o por límites de
solicitudes: hay que medir el flujo real en un staging aislado, con hardware,
PHP-FPM, Redis, base de datos y red equivalentes a la arquitectura candidata.

El primer perfil incluido está deliberadamente limitado a lecturas del POS
(bootstrap inicial y búsqueda de productos). No cobra, no descuenta inventario,
no crea ventas y no debe ejecutarse contra producción. Aún no mide impresión,
reportes, escritura concurrente de ventas ni e-CF.

## Arquitectura base que debe probarse

- DNS, TLS, CDN/WAF y balanceador delante de **al menos dos** servidores de
  aplicación sin estado (Nginx + PHP-FPM); no usar `artisan serve`.
- Redis compartido para sesiones, caché, locks y colas. Mantener `APP_KEY`,
  `SESSION_COOKIE`, `CACHE_PREFIX` y la configuración de tenant iguales en
  todas las réplicas.
- MySQL administrado en red privada, dimensionado a partir de métricas; no
  asumir que añadir servidores PHP resuelve el cuello de botella de DB.
  Medir conexiones, CPU, IOPS, latencia, bloqueos y consultas lentas.
- Workers supervisados y escalables por profundidad/edad de cola; separar
  colas de ventas, reportes y trabajos lentos. Confirmar que duplicados y
  reintentos sean idempotentes antes de habilitar la cola posventa.
- Almacenamiento compartido de objetos para archivos, logs/errores
  centralizados, copias de seguridad y restauración probada.
- Desplegar primero a staging. No comprar capacidad final ni apuntar el dominio
  real a la app hasta que pase la prueba de aceptación en ese entorno.

`.env.production.example` refleja Redis para sesiones y establece
`SCALE_TARGET_CONCURRENT_USERS=10000`. El preflight estricto bloquea esa meta
si caché, sesiones, cola y procesamiento posventa no están configurados para
Redis/worker. Esto es una puerta de configuración, no evidencia de capacidad.

## Generar sesiones de prueba

1. Crear un negocio de pruebas aislado en staging con productos activos y el
   mismo tipo de catálogo esperado en producción.
2. Crear suficientes usuarios de prueba únicos (hasta 10,000) y asignarlos al
   negocio. No usar cuentas ni datos de clientes reales.
3. Iniciar una sesión distinta por usuario a través del login normal de
   staging y exportar solo el valor de su cookie Laravel de sesión al archivo
   JSON. El archivo debe tener al menos tantos valores únicos como VUs.
4. Guardar `SESSION_POOL` fuera del repositorio, con permisos restringidos;
   contiene credenciales bearer de sesión. Cada fila debe incluir `user_id`
   y `cookie`, y ambos deben ser únicos entre usuarios de prueba. El ejemplo en
   `tests/load/.session-pool.example.json` no es una sesión válida.
5. Usar un hostname HTTPS con `staging` en el nombre. La prueba se niega a
   comenzar sin confirmación explícita y rechaza hosts que no parezcan staging.

## Ejecutar la prueba gradual

Instalar k6 en una máquina generadora separada de los servidores de aplicación
y base de datos. La máquina generadora también debe tener capacidad suficiente;
si satura primero, el resultado no es válido.

```bash
k6 run \
  -e LOAD_ENV=staging \
  -e CONFIRM_STAGING_LOAD=YES \
  -e CONFIRM_10K_LOAD=YES \
  -e BASE_URL=https://staging.example.com \
  -e POS_TENANT=negocio-prueba \
  -e SESSION_POOL=/ruta-segura/session-pool.json \
  -e MAX_VUS=10000 \
  -e STEP_DURATION=5m \
  -e HOLD_DURATION=15m \
  -e THINK_TIME_SECONDS=10 \
  tests/load/pos-readonly.js
```

La prueba sube por 10%, 25%, 50%, 75% y 100% de la meta; mantiene el máximo
durante el tiempo indicado. Cada VU usa una cookie distinta, hace bootstrap
una vez y después busca cada diez segundos. Por tanto, 10,000 VUs modelan
aproximadamente 1,000 búsquedas/segundo, no 10,000 escrituras/segundo.
`MAX_VUS=100` es el valor por defecto; subir de 500 requiere confirmación
adicional.

Antes de cada escalón, confirmar que no haya errores crecientes, 429, CPU
saturada, agotamiento de workers/conexiones, lag de Redis, consultas lentas ni
crecimiento de la cola. Cancelar la prueba si la latencia o errores se disparan.
No correr varias pruebas grandes a la vez.

## Criterios iniciales de aceptación

- Errores HTTP/validaciones en k6 inferiores al 1%; sin `429` inesperados.
- p95 de las respuestas POS menor a 1 s y p99 menor a 2 s.
- Sin pérdida ni inconsistencia de inventario en una prueba separada de
  checkout, con datos y tenant desechables.
- Sin saturación sostenida de CPU, memoria, PHP-FPM, Redis o DB; conservar
  margen operativo y comprobar recuperación tras el pico.
- Cola y trabajos posventa dentro del SLO acordado; no aceptar solo un buen
  promedio si p95/p99 fallan.
- Repetir la prueba al menos tres veces después de calentar cachés y medir
  crecimiento sostenido, pico y recuperación.

Estos límites son objetivos iniciales para staging, no una promesa comercial.
La prueba read-only no basta para aprobar el sistema completo ni para elegir
un tamaño de servidor: añadir escenarios de ventas concurrentes, reportes,
login, e-CF y dispositivos reales tras validar sus fixtures y límites de
negocio.
