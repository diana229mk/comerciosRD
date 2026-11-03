# INF-04: outbox contable transaccional

## Estado

**CORREGIDO Y VALIDADO en el alcance Docker aislado**, 2026-10-30:
53 pruebas/768 aserciones, cero fallos. Migración aplicada únicamente al esquema
aislado tras identidad, grants y autorización explícita. No autoriza despliegues
ni migraciones en bases reales. INF-02 conserva su evidencia histórica de 39/399;
los resultados no se suman. e-CF permanece sin cambios y desactivado.

## Atomicidad e identidad

`Sale::save()` abre una transacción si la creación o transición a completada no
está ya dentro de una. Esto incluye el UPDATE y los observers de confirmaciones
desde Filament, no solamente el trabajo posterior al guardado. `SaleService`
mantiene su transacción exterior: venta, líneas, inventario, caja, resumen y
evento se confirman juntos. Fallar el INSERT del evento revierte la confirmación.

`AccountingOutboxService::register` exige una venta persistida de la misma empresa
y conexión que el outbox. Hay un único evento por `(business_id, sale_id)` y un
UUID estable independiente del UUID del transporte Redis.

El consumidor bloquea la venta y el evento. El asiento, sus líneas, su vínculo
`journal_entry_id` y el estado `processed` se confirman en la misma transacción.
El vínculo es único: dos eventos no pueden apropiarse del mismo asiento. Una
reentrega de un evento procesado no depende de la referencia textual editable.

La generación del número contable se serializa con un contador SQL por
empresa/año en `accounting_sequences`, sin bloquear la fila de la empresa y
competir con los bloqueos compartidos de sus claves foráneas. Incluye la
numeración histórica y documentos eliminados lógicamente. No hay llamadas de
red dentro de esta transacción contable. Los otros productores de asientos
requieren validación específica antes de prometer concurrencia financiera global.

Prerequisitos: MySQL 8.4, tablas InnoDB y una sola conexión MySQL para venta,
outbox y contabilidad. No hay una transacción distribuida entre conexiones.

## Publicación y recuperación

En modo asíncrono se intenta publicar después del commit. Si Redis falla, el
evento persiste y el cajero no recibe un falso fallo que incentive repetir la
venta. Una caída de MySQL después del commit tampoco borra la intención.

Estados:

| Estado | Significado |
|---|---|
| `pending` | Pendiente o reintento programado. |
| `publishing` | Lease SQL reclamado; puede existir o no un mensaje Redis. |
| `published` | Mensaje enviado, todavía sin confirmación contable. |
| `processed` | Asiento vinculado y confirmado atómicamente. |
| `skipped` | Venta no completada al procesar; no genera asiento. |
| `failed` | Presupuesto agotado; se conserva para revisión y reapertura explícita. |

Una nueva confirmación soportada de una venta aún no contabilizada reabre su
evento `skipped` dentro de la misma transacción; no crea un segundo evento ni
reabre eventos `processed`.

El scheduler ejecuta `accounting:outbox-dispatch` cada minuto cuando está activo
`SCALE_POST_SALE_QUEUE`. No utiliza `onOneServer` ni locks de caché Redis: los
leases SQL y `FOR UPDATE SKIP LOCKED` coordinan publicadores concurrentes.

Se reclaman eventos en transacciones cortas y Redis se contacta después de
liberar el bloqueo. `lease_token` impide que una publicación tardía sobrescriba
un resultado más reciente. Solo `processed` confirma contabilización:
`publishing` y `published` vuelven a ser elegibles cuando vence `available_at`.
Esto recupera caídas antes del envío y trabajos perdidos después del envío.

No hay filtro de 24 horas ni límite histórico de 500 eventos. Cada invocación
es acotada; invocaciones sucesivas continúan con los demás pendientes.

El modo síncrono mantiene `dispatch_sync`: contabiliza y confirma el evento
dentro de la transacción de la venta, sin Redis. Un fallo sigue propagándose
para provocar rollback.

## Reintentos y operación

Valores predeterminados en `config/scale.php`:

| Variable | Valor | Función |
|---|---|---|
| `ACCOUNTING_OUTBOX_BATCH_SIZE` | 100 | Tamaño del lote, entre 1 y 1000. |
| `ACCOUNTING_OUTBOX_MAX_PUBLISH_ATTEMPTS` | 10 | Presupuesto de publicaciones, incluidas recuperaciones tras leases vencidos. |
| `ACCOUNTING_OUTBOX_MAX_PROCESSING_ATTEMPTS` | 10 | Presupuesto de intentos contables registrados. |
| `ACCOUNTING_OUTBOX_REDISPATCH_AFTER` | 180 s | Lease, nunca menor que la reserva Redis configurada. |
| `ACCOUNTING_OUTBOX_BACKOFF` | 30 s | Espera tras errores; mínimo configurable de 15 s. |

Se mantienen los tres intentos y backoff de 15 s del trabajo INF-02. El presupuesto
del outbox también cubre nuevas publicaciones con distintos UUID de transporte.
Las interrupciones antes de confirmar el intento contable quedan acotadas por
el presupuesto de publicación. Un backlog prolongado puede agotarlo: alertar
antes de alcanzar ese estado; no eliminar eventos ni reabrirlos masivamente.

Comandos operativos, **no ejecutados ni autorizados sobre bases reales**:

```bash
php artisan accounting:outbox-dispatch --batch=100 --batches=6
php artisan accounting:outbox-retry EVENT_UUID --business=BUSINESS_UUID
```

La reapertura exige empresa y evento exactos, conserva `last_error`, incrementa
`manual_retries` y renueva los presupuestos. No modifica ventas ni asientos.
No hay limpieza automática ni borrado en cascada de eventos. Los comandos son
para operadores confiables; no están expuestos a usuarios tenant.

Alertar por eventos `failed`, antigüedad de no procesados, ausencia del scheduler,
fallos de publicación y de almacenamiento de errores. Una cola vacía o
`failed_jobs` vacío no prueban integridad contable. El outbox sigue disponible si
el proveedor de fallos no conserva un registro.

## Compatibilidad histórica y límites

No se ejecuta backfill, ni se cambian registros históricos. El reconciliador
anterior se conserva para efectos heredados; su límite 500/24 h no limita el
recorrido de eventos del outbox.

Los trabajos INF-02 conservan constructor, empresa, venta y cola. Si un trabajo
antiguo no tiene evento, registra uno al procesarse. La compatibilidad por
referencia solo acepta un asiento activo, publicado y con líneas/balance
coherentes; borradores, duplicados y documentos archivados requieren revisión.
No se corrige ni elimina ninguno automáticamente.

La correspondencia histórica continúa siendo heurística cuando no existía
identidad de origen: referencias previamente cambiadas o colisiones históricas
requieren un censo autorizado antes del rollout. El outbox no descubre por sí
solo todas las ventas anteriores a su instalación que nunca tuvieron evento.

Se conserva el cálculo contable existente, incluido el fallback al costo actual
del producto cuando la línea no tiene costo positivo. Este cambio no introduce
un snapshot financiero inmutable ni resuelve ese riesgo de datos heredados.

No se garantiza recuperación de MySQL destruido sin respaldo, RPO/RTO, capacidad,
equidad entre empresas o supervisión real en DigitalOcean. Las escrituras SQL
directas y los cambios que silencian observers no son rutas de confirmación
soportadas por esta garantía.

## Migración y validación aislada

Única migración nueva:
`database/migrations/2026_10_29_140000_create_accounting_outbox_table.php`.
Solo crea `accounting_outbox`, `accounting_sequences` y sus índices; no hace backfill ni altera tablas
existentes. No ejecutar el migrador completo: hay migraciones históricas que
eliminan/reconstruyen tablas.
`down()` rechaza la eliminación automática del outbox y del contador; un
retroceso de código debe conservar estas tablas y requerir un plan de datos
separado, en lugar de borrar eventos recuperables o reutilizar números.

Antes de cualquier escritura:

1. Verificar identidad actual del proyecto, contenedores, red interna, volumen,
   ausencia de puertos publicados y grants exclusivos del esquema de pruebas.
2. Construir la imagen actual y ejecutar el preflight contable de solo lectura.
   Revisar UUID MySQL, identidad Redis y motores InnoDB. La presencia del outbox
   se informa separadamente: su ausencia inicial no autoriza crearla.
3. Obtener autorización explícita para la migración nueva y las pruebas
   seleccionadas. No reutilizar la autorización o identidad histórica de INF-02.
4. Aplicar exclusivamente la migración nueva en `comercios_rd_storage_test`;
   comprobar la presencia del esquema mediante el preflight.
5. Ejecutar únicamente `tests/Integration/PostSaleAccountingQueueTest.php` y,
   si se autorizan, las regresiones financieras/postventa existentes.

La aplicación aislada usa `tests/Fixtures/accounting-outbox-migrate.php --apply`.
Este auxiliar exige ambiente, host, grants, esquema y tablas nuevas ausentes
antes de invocar el migrador con una única ruta literal. Nunca se ejecuta
como comando predeterminado del runner.

Las pruebas usan copias vacías con prefijo aleatorio y colas propias. Su limpieza
solo afecta esos recursos creados por el caso. Incluyen las regresiones INF-02,
fallos de inserción/publicación, recuperación sin `failed_jobs`, leases vencidos,
501 eventos antiguos, presupuestos, identidad durable y numeración concurrente.
La pérdida Redis se simula limpiando exclusivamente la cola aleatoria del caso;
la indisponibilidad de publicación se inyecta en la interfaz de cola. No son un
ensayo físico de restauración AOF/RDB ni una caída real de MySQL.

No ejecutar `migrate:fresh`, seeders, suite general, limpieza amplia o migraciones
históricas. No iniciar staging ni producción como parte de esta validación.

## Evidencia de ejecución

El usuario autorizó la migración aditiva y exactamente 53 pruebas dirigidas
después del preflight. La migración terminó en el runner `559a4fe6e33d`; el
preflight posterior confirmó ambas tablas InnoDB.

| Identidad | Valor verificado |
|---|---|
| Proyecto/red | `comercios-rd-isolated-tests` / `comercios_rd_isolated_test_backend`, interna |
| Volumen MySQL | `comercios_rd_isolated_test_mysql` |
| MySQL | `ef3c89813c5c`, UUID `bfd92f30-d1c5-11f1-beb5-b24b172266ee`, 8.4.13 |
| Conexión SQL | `test-mysql`, `comercios_rd_storage_test`, `comercios_storage_test@%`, guard PASS |
| Redis | `1a271a30edc6`, `test-redis`, DB 15, 7.4.11 |
| Redis run_id | `90b659debe0bebf482d67194967a7eb1ca102881` |
| Imagen validada | `sha256:f32744defd7a30cd8ab7ccdf6eae78d77a536bd58b148fd121bdc8568f780e3f` |
| Preflight final | `a3e7419653ea` |
| Runner de pruebas | `4abd8bf6f153` |

No hay puertos SQL/Redis publicados al host. Los 25 casos de
`PostSaleAccountingQueueTest`, 18 de `FinancialOperationsTest`, cinco de
`ScaleDailySummaryTest` y cinco de `EcfDisabledTest` terminaron:

```text
PHP 8.3.36 / PHPUnit 11.5.57
Time: 02:00.377, Memory: 74.50 MB
OK (53 tests, 768 assertions)
```

La primera ejecución (`8c52eaf0605c`) falló seis casos de routing, 53 pruebas/672
aserciones. `Queue::push()` no hereda automáticamente la propiedad `queue` del
trabajo: se corrigió pasando la cola explícita. La imagen fue reconstruida y
las 53 pruebas repetidas, sin migraciones adicionales. No se cambiaron archivos
funcionales después del resultado satisfactorio.

Se validaron eventos y rollback, fallos inyectados de Redis, leases caducados,
recuperación sin registro `failed_jobs`, 501 eventos de diez días, interrupción
antes del ACK, reentregas, publicadores/consumidores simultáneos, identidad por
empresa/venta, presupuestos y modo síncrono. No es un simulacro físico de caída
de MySQL, persistencia AOF/RDB, capacidad o supervisión en DigitalOcean.

Tras la validación, el usuario detuvo exclusivamente Redis de pruebas
`1a271a30edc6` y confirmó estado `exited`. No se detuvo MySQL ni se eliminaron
contenedores o volúmenes.
