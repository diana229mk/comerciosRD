# Checklist final de despliegue — Comercios RD en DigitalOcean

Fuente única de seguimiento hacia producción. Complementa, sin repetir, a
[PRODUCTION_READINESS_AUDIT.md](PRODUCTION_READINESS_AUDIT.md).

- **Rama:** `fix/production-readiness-payments-and-branches`.
- **Último commit integrado:** `75a7415`, con push.
- **Corte de este checklist:** 2026-11-22.

**Estado de producción: NO APTO.** La activación requiere, además, la
autorización expresa del responsable.

## Leyenda de estados

| Estado | Significado |
|---|---|
| **PASS** | Completado y verificado con evidencia de esta revisión o de la ejecución actual. |
| **HIST** | Completado con evidencia histórica, en un alcance ya documentado y sin cambios posteriores que lo invaliden. |
| **PREP** | Preparado en el repositorio, pero sin ejecutar ni verificar. |
| **PEND** | Pendiente. |
| **BLOQ** | Bloqueado por una dependencia externa o una autorización. |
| **N/A** | No aplica. |

**Prioridades:** P0 bloquea el despliegue; P1 se exige antes de recibir datos
reales; P2 se exige antes de escalar.

## FASE 1 — Diagnóstico consolidado

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| D-01 | Inventario Git y cambios pendientes | P0 | PASS | 43 cambios sin commit (2026-11-22), repartidos en los grupos A–F sin solapamientos y un archivo local excluido. La rama está alineada con `origin`. | — | — |
| D-02 | Rama de integración frente a `main` | P0 | PREP | PR #1 abierto hacia `main` (https://github.com/diana229mk/comerciosRD/pull/1); rama publicada. CI run `45037433737` (commit `c3c8912`, PR #1): 4/4 jobs PASS. | Fusión requiere autorización | Revisar y autorizar la fusión del PR #1. |
| D-03 | Vulnerabilidades de dependencias (`composer audit --locked`) | P0 | PASS | 2026-11-22: `laravel/framework` 12.70.1 → 12.73.0 (solo ese paquete). `composer audit --locked`: sin avisos. Validado en V-01 (485 PASS). | — | — |
| D-04 | Vulnerabilidades JS e imagen base | P1 | PASS | `npm audit --omit=dev`: 0 vulnerabilidades. Trivy sobre la imagen de producción (`--target app`): sin CRITICAL/HIGH con parche, CI run `45037433737` (commit `c3c8912`, PR #1). | — | — |
| D-05 | Alcance de e-CF | P0 | PASS | `ECF_ENABLED=false` está fijado en `compose.production.yaml` y lo exige `docker/production-guard.sh`. | — | — |

## FASE 2 — Integración de cambios pendientes

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| G-A | Commit A: branch scope en `SaleObserver`, `AccountingOutboxService` y `PosApiTest` | P0 | PASS | Diff en `commits/A.diff`, validado en V-01 (imagen `20261122`, 485 PASS + TLS 16/123). | — | Commit local `5b93600`; push pendiente de autorización (D-02). |
| G-B | Commit B: proxies, Redis TLS, preflight y disco público | P0 | PASS | Diff en `commits/B.diff`, validado en V-01 (imagen `20261122`, 485 PASS + TLS 16/123). Incluye las claves `portability.db_username/password` que usa F. Los tests `EdgeSecurityTest`, `SecurityPreflightTest` y `RedisTlsTest` se incluyen en el commit. | — | Commit local `2b9dccc`; push pendiente de autorización (D-02). |
| G-C | Commit C: paquete DigitalOcean (con separación de cuentas, rollback y monitor) | P0 | PASS | `commits/C.diff` regenerado: 21 archivos (incluye `rollback-do.sh`, `ops-check.sh`, `mysql-accounts.template` y `tests/deploy/production-guard-roles.sh`); validado en V-01 (imagen `20261122`, 485 PASS + TLS 16/123). Escaneo de secretos: solo marcadores `REPLACE_WITH_*` y valores de prueba. | — | Commit local `6266714`; push pendiente de autorización (D-02). |
| G-D | Commit D: `composer.lock` y `.dockerignore` | P0 | PASS | Incluye Symfony y Laravel 12.73.0. Audit limpio; validado en V-01 (imagen `20261122`, 485 PASS + TLS 16/123). | — | Commit local `4437e45`; push pendiente de autorización (D-02). |
| G-F | Commit F: `LoginUnlock`, credenciales de backup, CI y checklist | P0 | PASS | 5 archivos: `LoginUnlock.php`, `PortabilityBackup.php`, `PortabilityWorkflowTest.php`, `.github/workflows/ci.yml`, `DEPLOYMENT_FINAL_CHECKLIST.md`; validado en V-01 (imagen `20261122`, 485 PASS + TLS 16/123). Depende de B (config) y C (script del guard que usa CI). | — | Commit local `cdc3b08`; push pendiente de autorización (D-02). |
| G-E | Commit E: estado consolidado en la auditoría | P1 | PASS | Registra las 483 pruebas y `MysqlTlsTest` 16/123. | — | Commit local `c8a9d6c`; push pendiente de autorización (D-02). |
| G-X | Excluir `compose.data02-validation.yaml` | P1 | PASS | Queda sin rastrear por decisión del responsable. | — | — |

## FASE 3 — Seguridad y bases de datos

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| S-01 | Guard de pruebas: bloquear conexiones fuera del runtime autorizado | P0 | PASS | Commits `64eb858` y `0f0540d`. La suite de 2026-11-21 incluyó sondas negativas y `DatabaseConnectionGuardTest`, `DatabaseTargetPolicyTest` y `ArtisanDatabaseGuardTest`. | — | — |
| S-02 | Bloqueo de comandos destructivos (`migrate:fresh`, `refresh`, `reset`, `db:wipe`, seeders) | P0 | PASS | `ArtisanDatabaseGuardTest` (20 pruebas) en la suite de 483. | — | — |
| S-03 | `TRUNCATE` fuera del bloqueo DDL | P2 | PASS | El único uso (`login:unlock --all`) pasa a `DELETE` (F-03). En producción, `comercios_app` no tiene `DROP`, así que MySQL rechaza `TRUNCATE`. | — | — |
| S-04 | Cuentas MySQL separadas en el host (app y migrador) | P0 | HIST | Medida 6, 2026-11-20: `comercios_app` solo DML, `comercios_migrator` con DDL, `comercios_user` bloqueada, con reversión disponible. | — | — |
| S-05 | Separar cuentas en Managed MySQL (app, migrador, backup) | P0 | BLOQ | Preparado: `deploy/digitalocean/mysql-accounts.template`; `init-do-env.sh` genera las contraseñas y el SQL (0600). | Recurso externo (F-05) | Aplicarlo como `doadmin` en staging y comprobar `SHOW GRANTS`. |
| S-06 | Usuario de pruebas aislado | P0 | PASS | `comercios_storage_test` y `comercios_data02_test` con privilegios solo sobre `comercios_rd_storage_test`, confirmado por el preflight TLS de 2026-11-22. | — | — |
| S-07 | Aislamiento multiempresa (`business_id`) | P0 | HIST | Casos de SEC-01 y SEC-02 (`1138287`) y `EdgeSecurityTest` dentro de las 483. SEC-03 (contrato de NULL) sigue abierto. | Decisión funcional sobre SEC-03 | Cerrar SEC-03 (S-08). |
| S-08 | SEC-03: contrato de registros con `branch_id` NULL | P1 | PEND | La auditoría lo marca PENDIENTE de definición funcional. No se demostró un IDOR entre empresas. | Decisión de producto | Definir por entidad qué se comparte entre sucursales. |
| S-09 | Integridad financiera (cobros duplicados, sobrepago, idempotencia, concurrencia) | P0 | PASS | Suite de 2026-11-21 con imagen `20261121b`: Financial 50/201, SaleCredit 42/112, concurrencia CxC 16/16, cola contable 41/41. | — | — |
| S-10 | Reembolsos, caja y asientos | P0 | HIST | Incluidos en Feature (295) y en las regresiones F1, INF-02 e INF-04. | — | — |
| S-11 | Legado de INF-05 (ventas antiguas sin evento) y reconciliador | P1 | PEND | La auditoría lo deja PENDIENTE. Con una base nueva en DigitalOcean no hay legado si no se migran datos. | Decidir si se migran datos de `comercios_rd` | Definir la carga inicial (O-07). |
| S-12 | HTTP-01: encabezados duplicados entre Nginx y el middleware | P2 | PREP | Commit `3b87eb2`: Nginx oculta los tres encabezados de FastCGI y los emite una sola vez desde el bloque `server`. `nginx -t` PASS en CI (run `45037978747`). | V-05 | Verificar en staging que cada encabezado aparece una vez (HTML, estáticos y errores). |
| S-13 | TLS de MySQL | P0 | PASS | `MysqlTlsTest` 16/123 el 2026-11-22 (`TLS_AES_256_GCM_SHA384`, rechazo de CA incorrecta, de hostname discordante y de servidor sin TLS). | — | — |

## FASE 4 — Infraestructura y configuración

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| F-01 | El servicio `migrate` usa credenciales del migrador, no las de la app | P0 | PREP | `migrate` carga `migrator.env` y `COMERCIOS_DB_ROLE=migrator`; el guard rechaza la cuenta de la app y `doadmin`. Compose renderizado: `migrate`→`comercios_migrator`, app→`comercios_app`. `tests/deploy/production-guard-roles.sh`: 12/12. | Verificación real en staging (V-02) | — |
| F-02 | Credenciales de backup separadas para `portability:backup` | P0 | PASS | `PORTABILITY_DB_USERNAME`/`PASSWORD` solo en el scheduler (`backup.env`). El guard exige backup.env y rechaza reutilizar la cuenta de la app. Dos pruebas nuevas en `PortabilityWorkflowTest`, aprobadas en V-01. | — | Confirmar la cuenta de backup en staging (V-04). |
| F-03 | `login:unlock --all` sin `TRUNCATE` | P1 | PASS | `LoginUnlock.php`: `delete()`. `php -l` y Pint OK; suite V-01 485 PASS. | — | — |
| F-04 | Producción falla en cerrado (production guard y preflight estricto) | P0 | HIST | `docker/production-guard.sh` y `SecurityPreflightTest` dentro de las 483. | — | — |
| F-05 | Droplet, VPC, Managed MySQL 8.4, dominio, DNS y correo SMTP | P0 | BLOQ | No existen o no se han verificado. | Cuenta de DigitalOcean, dominio y proveedor SMTP | El responsable crea los recursos (lista en `docs/digitalocean.md`). |
| F-06 | Cloud Firewall (22 solo desde la IP de administración; 80 y 443 públicos) | P0 | BLOQ | Solo documentado. Docker publica puertos por encima de `ufw`, así que hace falta el firewall de DigitalOcean. | F-05 | Crearlo y verificarlo con un escaneo externo en staging. |
| F-07 | Endurecimiento SSH (claves, sin root ni contraseña) y actualizaciones automáticas | P1 | PREP | `bootstrap-droplet.sh`: `unattended-upgrades` y un drop-in de sshd (sin contraseña, root solo con clave, `MaxAuthTries 3`). Se aplica solo si existe una clave y `sshd -t` pasa. | F-05 | Verificarlo en el Droplet de staging. |
| F-08 | HTTPS automático (Caddy y ACME) | P0 | PREP | `deploy/caddy/Caddyfile`. Sin verificar: requiere DNS. | F-05 | Verificarlo en staging. |
| F-09 | Sesiones, caché, colas y mantenimiento en Redis con contraseña | P0 | PREP | `compose.production.yaml` y el guard (contraseña de 16 caracteres o más). | — | Verificarlo en staging. |
| F-10 | Worker y scheduler supervisados (`restart`, healthcheck, `onOneServer`) | P0 | PREP | Healthchecks por `cmdline`: no prueban progreso real. | — | Añadir una alerta de cola atascada (O-03). |
| F-11 | Logs con rotación | P1 | PREP | json-file de 10 MB × 5 y `LOG_CHANNEL=stderr`. | — | Verificarlo en staging. |
| F-12 | Archivos privados fuera de `public/` | P0 | HIST | Pruebas de documentos privados dentro de las 483. | — | — |
| F-13 | Almacenamiento: disco local en el primer nodo; Spaces para escalar | P2 | PREP | El disco público es configurable (grupo B). | — | — |
| F-14 | Capacidad de almacenamiento del host de trabajo | P1 | PASS | 6,8 GB libres (88 %). Imagen `:20261121` retirada. | — | No construir más imágenes sin comprobar el espacio. |

## FASE 5 — Automatización del despliegue

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| P-01 | CI: validación, lint y pruebas en GitHub Actions | P1 | PASS | `.github/workflows/ci.yml`: CI run `45037433737` (commit `c3c8912`, PR #1): validación estática, dependencias JS, Unit en Docker aislado (127/264) y escaneo de imagen, 4/4 PASS. Primera ejecución falló por `restrict-test-user.sql` ignorado por git y `trivy-action@0.28.0` inexistente; corregido en `c3c8912` (Trivy fijado por SHA a v0.36.0). | — | — |
| P-02 | Despliegue reproducible con imágenes etiquetadas por commit | P0 | PREP | `deploy-do.sh` exige checkout limpio, etiqueta `comercios-rd-{php,nginx}:<sha12>` y registra `deploy-state/current` y `previous`. | V-02 | Ensayar en staging. |
| P-03 | Escaneo de vulnerabilidades de las imágenes | P1 | PASS | Trivy v0.36.0 (fijado por SHA) en CI: CRITICAL/HIGH con parche, `exit-code: 1`; PASS en CI run `45037433737` (commit `c3c8912`, PR #1). | — | — |
| P-04 | Respaldo obligatorio antes de migrar | P0 | PREP | `deploy-do.sh` ejecuta `portability:backup` (cuenta de backup) antes de `migrate`; si falla, no migra. También registra `schema-changed-<sha>`. | V-02 | Ensayar en staging. |
| P-05 | Rollback de código separado del de esquema | P0 | PREP | `deploy-scripts/rollback-do.sh` (sin build, sin migraciones, conserva réplicas). Se niega si un despliegue posterior migró. Probado con un `docker` simulado: 6/6 casos. | V-07 | Ensayar en staging. |
| P-07 | Pint como puerta de CI | P2 | PEND | 235 archivos heredados no cumplen el estilo. | — | Reformatear en un commit aislado, sin cambios de lógica. |
| P-08 | Suites Feature e Integration en CI | P1 | PEND | Necesitan un esquema de pruebas aprovisionado (hoy se hace con fixtures por entorno). | — | Diseñar un job que migre `comercios_rd_storage_test` en un MySQL efímero. |
| P-06 | Procedimiento ante un fallo de despliegue | P0 | PREP | `docs/digitalocean.md`, sección «Fallo de despliegue y rollback»: tres escenarios, mantenimiento y restauración en una base nueva. | V-07 | — |

## FASE 6 — Validación en staging

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| V-01 | Reconstruir la imagen de pruebas y ejecutar la suite completa tras A–F | P0 | PASS | Imagen `20261122` (`04724f14`), 2026-11-22: Unit 127, Feature 295 (2 omitidas, LiveSmoke), Concurrencia CxC 16, Colas contables 41, Restauración MySQL 6: **485 PASS**. `MysqlTlsTest` 16/123 PASS. `comercios_rd` sin cambios (72 72 2 76). | — | No repetir salvo cambios de código. |
| V-02 | Staging en DigitalOcean (Droplet y base separados de producción) | P0 | BLOQ | — | F-05 | Desplegar con `deploy-do.sh` y `COMERCIOS_ENV_FILE` de staging. |
| V-03 | Healthchecks y `/up` en staging | P0 | BLOQ | — | V-02 | — |
| V-04 | Prueba funcional de humo (login con 2FA, venta, crédito, cobro, devolución, caja, reportes) | P0 | BLOQ | — | V-02 | Seguir un guion de aceptación. |
| V-05 | Escaneo externo de puertos y encabezados HTTPS | P0 | BLOQ | — | V-02 | `nmap` y `curl -I`. |
| V-06 | Backup en staging y restauración en una base nueva | P0 | BLOQ | La restauración aislada local está verificada (HIST); falta hacerlo en DigitalOcean. | V-02 | `portability:backup` y `portability:restore` hacia una base nueva. |
| V-07 | Rollback de código ensayado | P0 | BLOQ | — | V-02, P-05 | — |

## FASE 7 — Operación y revisión final

| ID | Tarea | Prioridad | Estado | Evidencia | Bloqueo | Próxima acción |
|---|---|---|---|---|---|---|
| O-01 | Copia externa cifrada del respaldo de `comercios_rd` | P0 | BLOQ | Aplazada: el responsable no tiene USB. | Medio externo | Usar USB o Spaces cifrado. |
| O-02 | Backups automáticos fuera del Droplet | P0 | PEND | Managed MySQL incluye backups diarios del plan; el volumen `production_backups` vive en el propio Droplet. | F-05 | Sincronizar ese volumen con Spaces, cifrado y con retención. |
| O-03 | Monitoreo y alertas (CPU, disco, memoria, `/up`, cola, edad del último backup) | P0 | PREP | `deploy-scripts/ops-check.sh` (cron: `/up`, contenedores, disco, backup < 26 h; syslog y webhook), probado con un `docker` simulado. Monitoring y Uptime de DigitalOcean documentados. | F-05; webhook o correo de alertas | Activarlo en staging y provocar una alerta. |
| O-04 | Recuperación ante desastres (RPO/RTO) | P1 | PEND | La auditoría lo deja PENDIENTE. | V-06 | Ensayo integral. |
| O-05 | Usuario master con 2FA | P0 | BLOQ | `make:master-admin` | V-02 | — |
| O-06 | Revisión final y autorización expresa | P0 | BLOQ | — | Todos los P0 | — |
| O-07 | Carga inicial de datos: base vacía o migración desde `comercios_rd` | P0 | PEND | `docs/cutover-proveedor.md`. Afecta a S-11. | Decisión del responsable | Decidir. |

## Registro

| Fecha | Acción | Resultado |
|---|---|---|
| 2026-11-21 | Suite completa con la imagen `20261121b` | 483 PASS |
| 2026-11-21 | Commit `75a7415` con push | 6 archivos de prueba |
| 2026-11-22 | Retirada de la imagen `:20261121` | PASS; nada más eliminado |
| 2026-11-22 | `MysqlTlsTest` (proyecto `comercios-rd-tls-20261121b`) | 16/123 PASS; recursos detenidos y conservados |
| 2026-11-22 | Diagnóstico de la FASE 1 | Este documento |
| 2026-11-22 | Laravel 12.73.0 y `composer audit` | Sin avisos |
| 2026-11-22 | F-01, F-02, F-03, F-07, P-01, P-02, P-04, P-05, P-06 y O-03 preparados | Guard 12/12; rollback 6/6; `ops-check` 3/3; render del Compose OK. Las pruebas PHP quedan para V-01. |
| 2026-11-22 | Build `20261122`: fallo por `mysql-accounts.sql.template` (filtro `*.sql*`); renombrado a `mysql-accounts.template` | Corregido; nada creado |
| 2026-11-22 | V-01: imagen `20261122` (`04724f14`), suite completa y `MysqlTlsTest` (`comercios-rd-tls-20261122`, conservado) | 485 PASS (2 omitidas LiveSmoke); TLS 16/123 PASS; `comercios_rd` 72 72 2 76 |
| 2026-11-22 | Commits locales A–F (`5b93600`…`c8a9d6c`), sin push | 43 archivos; árbol limpio salvo `compose.data02-validation.yaml` |
| 2026-11-22 | Push de la rama y PR #1 hacia `main`; corrección de CI (`c3c8912`) | CI 4/4 PASS (run `45037433737`); sin fusionar |
| 2026-11-22 | HTTP-01 corregido en Nginx (`3b87eb2`) y `nginx -t` en CI | CI 4/4 PASS (run `45037978747`) |
