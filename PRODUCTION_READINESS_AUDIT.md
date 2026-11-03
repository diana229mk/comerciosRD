# Auditoría de preparación para producción — Comercios RD

Fecha de corte: 2026-10-28. Destino evaluado: DigitalOcean. Stack: Laravel 12, Filament 3, MySQL 8.4, Docker, Nginx y Redis.

> **Seguimiento estricto validado:** tras verificar el contenedor, volumen, red y grants de MySQL aislado, el usuario autorizó y ejecutó 36 pruebas/258 aserciones, sin fallos. SEC-01 y DATA-01 quedan **CORREGIDOS Y VALIDADOS** en el alcance de conteos descrito. Se exige sucursal activa concreta, sin lotes/conteos NULL, y guardar/finalizar/cancelar se coordinan bajo bloqueo. No equivale a aprobación de producción.

> **Seguimiento INF-02: CORREGIDO Y VALIDADO en el alcance aislado.** Tras verificar MySQL/Redis y recibir autorización expresa, el usuario ejecutó **39 pruebas/399 aserciones, sin fallos**, incluidas once regresiones con Redis real y workers independientes. Se verificaron consumo, rollback, reintentos, concurrencia, aislamiento y recuperación tras muerte del worker sin duplicados. Reserva Redis 180 s, timeout 90 s y gracia declarada 120 s. No acredita todavía el despliegue/supervisión en DigitalOcean ni durabilidad atómica MySQL/Redis.

> **Seguimiento INF-03/INF-04 — actualizado 2026-10-30:** INF-03 concluyó como auditoría estática, sin hallazgos CRÍTICOS confirmados, pero con cierre operativo pendiente. El usuario autorizó INF-04 para resolver sus riesgos ALTOS. Outbox MySQL por empresa/venta, confirmación atómica con el asiento, leases SQL, recuperación sin ventana histórica y reintento explícito: **CORREGIDO Y VALIDADO en el alcance Docker aislado**, con **53 pruebas/768 aserciones, cero fallos**. No acredita producción, recuperación física de Redis/MySQL ni tratamiento completo del legado. Diseño, evidencia y límites en [docs/accounting-outbox.md](docs/accounting-outbox.md).

> **Alcance de validación INF-04:** migración aditiva para `accounting_outbox` y `accounting_sequences` aplicada solo al esquema de pruebas, sin backfill ni eliminación automática mediante `down()`. Ejecución autorizada de 25 casos de integración (once INF-02 y catorce nuevos), 18 financieros, cinco resúmenes y cinco e-CF desactivado. Identidad/grants/motores verificados antes de escribir. El usuario ejecutó los comandos privilegiados; el agente no accedió al socket Docker ni a bases. Sin migrador general, seeders, credenciales/configuraciones desplegadas o activación e-CF.

> **Fallo inicial y cierre INF-04 — 2026-10-30:** migración `559a4fe6e33d`, preflight posterior `b17ed2af807c` PASS. Primera ejecución `8c52eaf0605c`: 53 pruebas/672 aserciones/seis fallos; no se utiliza como validación positiva. Causa: `Queue::push()` sin argumento de cola utilizaba `default`. Se corrigió con la cola explícita del trabajo, se reconstruyó la imagen `sha256:f32744defd7a30cd8ab7ccdf6eae78d77a536bd58b148fd121bdc8568f780e3f` y se renovó preflight (`a3e7419653ea`). Repetición `4abd8bf6f153`: **OK (53 tests, 768 assertions)**, PHP 8.3.36/PHPUnit 11.5.57, 120.377 s, 74.50 MB. Sin nuevas migraciones ni cambios funcionales posteriores.

> **Identidad INF-04:** MySQL `ef3c89813c5c`, UUID `bfd92f30-d1c5-11f1-beb5-b24b172266ee`, host `test-mysql`, esquema `comercios_rd_storage_test`, cuenta `comercios_storage_test@%`, guard PASS. Volumen `comercios_rd_isolated_test_mysql`, red `comercios_rd_isolated_test_backend` interna, sin puertos publicados. Redis `1a271a30edc6`, host `test-redis`, DB 15, run_id `90b659debe0bebf482d67194967a7eb1ca102881`. Ambas tablas nuevas y las tablas financieras verificadas son InnoDB. Los resultados no se suman con los 39/399 históricos.

> **Cierre temporal INF-04:** el usuario detuvo únicamente Redis `1a271a30edc6` y confirmó `exited`. MySQL permanece sin detener; no se eliminaron contenedores o volúmenes.

> **INF-06 — cierre del lote INF-01, 2026-10-31: CORREGIDO Y VALIDADO en Docker aislado.** El usuario autorizó TLS autenticado fail-closed, preparación y pruebas dirigidas. Resultado: **72 pruebas/281 aserciones, cero fallos**, más cuatro checks Oracle aprobados: conexión verificada y rechazo de CA incorrecta, hostname discordante y servidor sin TLS. Se conserva la excepción local sin TLS obligatorio y el socket explícito cuando corresponde. No se ejecutaron migraciones, seeders, suite general o despliegues; no se modificaron credenciales/configuraciones desplegadas ni el outbox. Detalles, incidentes y alcance en [docs/mysql-tls.md](docs/mysql-tls.md). INF-05 y los hallazgos MEDIOS siguen pendientes; el SaaS continúa **NO APTO PARA PRODUCCIÓN**.

> **INF-05/F1 — actualizado 2026-11-02: CORREGIDO Y VALIDADO en el alcance Docker aislado probado.** Barrera transaccional en modelos y consultas originadas en ellos, construcción inicial delimitada y protección de líneas/traslados. Tras corregir exclusivamente fixtures, renovar imagen/preflight y recibir autorización expresa de repetición, resultado: **97 pruebas/999 aserciones, cero errores y fallos**. La primera ejecución 97/915/12 errores se conserva como incidente, no como evidencia positiva. Incluye ocho escenarios de contención entre conexiones MySQL independientes y regresiones INF-02/INF-04. No acredita inmutabilidad frente a SQL directo/cascadas FK ni aceptación completa de UI o producción. No se ejecutaron migraciones ni se modificaron datos históricos, credenciales o servicios productivos; no se eliminaron contenedores o volúmenes existentes. F2, F6, legado y supervisión siguen pendientes; **INF-05 completo NO VALIDADO**. El SaaS permanece **NO APTO PARA PRODUCCIÓN**.

> **INF-05/F2 — diagnóstico y diseño estáticos, 2026-11-02: DEFECTO CONFIRMADO EN CÓDIGO — CORRECCIÓN NO IMPLEMENTADA.** La creación de CxC puede omitirse o fallar sin abortar la confirmación; no existe vínculo único por ID de venta. Se documentan atomicidad, idempotencia, concurrencia, límites y aceptación debajo. No se consultaron datos ni se ejecutaron aplicación, PHPUnit, migraciones o acciones Docker. Las 97/999 de F1 no validan F2. Al cerrar el diagnóstico se autorizó un siguiente lote F2 y preparación de pruebas/esquema, sin implementarlo en esta fase; incluye rechazo de crédito sin módulo CxC y dependencia acotada DATA-02. Aplicar DDL, operar Docker y ejecutar pruebas siguen pendientes de autorización específica. SaaS **NO APTO PARA PRODUCCIÓN**.

> **INF-05/F2 — actualizado 2026-11-02 14:23 -05:00: IMPLEMENTADO; PENDIENTE DE VALIDACIÓN FUNCIONAL.** Sintaxis PHP/Pint y sintaxis JavaScript aprobados en el alcance documentado. Build, topología y baseline aislados verificados. El usuario aplicó exclusivamente la migración F2 autorizada en `comercios_rd_storage_test`: guard previo PASS, registro id 71/batch 3 y **preflight de esquema PASS**, con 22 tablas InnoDB, MySQL 8.4.13/UUID/cuenta/grants esperados y Redis DB 15 accesible. SHOW CREATE confirma columnas, unicidades, FK y CHECK preparados. Sin PHPUnit, seeders ni backfill histórico ejecutados en esta etapa. La evidencia 97/999 es anterior a estos cambios y no acredita esta revisión de F1/F2. SaaS **NO APTO PARA PRODUCCIÓN**.

> **INF-05/F2 — actualización 2026-11-03: VALIDADO EN DOCKER AISLADO; NO APROBADO PARA PRODUCCIÓN.** Tras corregir el fixture `Business` sin `slug` obligatorio y ajustar la cola simulada para excluir el job de contabilidad síncrona `PostSaleAccountingJob`, la validación funcional del archivo F2 quedó ejecutada en la base de pruebas aislada con **42 pruebas / 112 aserciones, todos OK**. El comando se ejecutó solo sobre MySQL/Redis del entorno aislado `comercios_rd_storage_test` / `REDIS_DB=15`, sin tocar producción ni ejecutar migraciones/seeders/builds en destino. El resultado confirma la integridad del flujo F2 en ese alcance, pero **no acredita producción** ni cierre total de DATA-02/lagacy; el SaaS sigue **NO APTO PARA PRODUCCIÓN**.

### Seguimiento INF-06: hallazgos y prioridades

La auditoría de seguridad fue de lectura; las correcciones se limitaron después
al primer lote autorizado. No se confirmó un hallazgo CRÍTICO. No equivale a
ausencia global de vulnerabilidades ni a inspección del runtime de DigitalOcean.

| Hallazgo | Severidad | Impacto y evidencia | Estado |
|---|---|---|---|
| INF-01: MySQL sin TLS autenticado uniforme | ALTO | El lector PDO independiente y los CNF de backup/restore omitían CA/verificación; comparar UUID no autentica el endpoint. `PdoMysqlServerIdentityReader::target`, `PortabilityBackup::mysqlCnf`, `PortabilityRestore::mysqlCnf`. Riesgo de interceptación condicionado a red/endpoint; no se demostró tráfico productivo en claro. | CORREGIDO Y VALIDADO en el alcance aislado: 72/281 y cuatro checks Oracle; producción NO VERIFICABLE. |
| DATA-02: pagos no atómicos y sobrepago concurrente | MEDIO | `Receivable::registerPayment` y `Payable::registerPayment` verifican saldo del modelo antes de insertar; observadores recalculan agregado sin bloqueo común. Pago, saldo y caja pueden persistir parcialmente. `Receivable.php:119-156`, `Payable.php:112-140`. | PENDIENTE; no incluido en el lote seleccionado. |
| SEC-02: sucursal inactiva reconstruida | MEDIO | `BranchAccessContext.php:134-141`, `CheckBranchActivation.php:40-47`, `AuthContextResolver.php:250-252`, `BindPosTenant.php:35-55`: limpiar la sesión no impide reconstruir contexto desde una membresía activa sin exigir sucursal activa. | PENDIENTE fuera de conteos ya validados. |
| SEC-03: contrato de operaciones NULL incompleto | MEDIO | `BranchScope.php:47-54`, `TenantPolicy.php:79-87`, `TracksBranch.php:28-80`: excepciones compartidas/globales requieren contrato por entidad. No se demostró un IDOR interempresa general por NULL. | PENDIENTE de definición funcional; no cambiar permisos sin aprobación. |
| DATA-03: costo histórico sustituido por costo actual | MEDIO | `SaleService` captura `unit_cost`, pero `AccountingService::createFromSale` recurre al producto mutable cuando el costo es cero/ausente. Un cero legítimo no significa dato faltante. | PENDIENTE; diseño de snapshot debajo, sin backfill ni cambio financiero en este lote. |
| DEP-01: Symfony MIME vulnerable | MEDIO | `composer audit --locked`: `symfony/mime` 7.4.19, CVE-2026-45070 / PKSA-wtxr-p26d-nn42; versión corregida compatible 7.4.22+. No avisos ALTOS/CRÍTICOS ni paquetes abandonados en esa ejecución. | PENDIENTE de actualización y regresiones autorizadas; dependencias sin modificar. |
| HTTP-01: encabezados duplicados | BAJO | Nginx y middleware `SecurityHeaders` declaran controles repetidos. No se observó bypass; pruebas Laravel no atraviesan Nginx. | PENDIENTE de aceptación HTTP real. |
| INF-05/F1: fuente financiera confirmada editable | ALTO | Método, importes, fechas, origen contable y composición de líneas podían cambiar sin modificar el asiento original ya procesado. | CORREGIDO Y VALIDADO en el alcance Docker probado: 97/999, incluidas ocho contenciones MySQL; límites SQL y de aceptación descritos debajo. |
| INF-05/F2: venta a crédito confirmada sin CxC válida | ALTO | Defecto original: observer omitía/capturaba errores de CxC; referencia textual sin unicidad. Corrección en código y fixture aislado con `slug` obligatorio; migración aplicada solo al esquema de pruebas y preflight SQL PASS. | VALIDADO EN DOCKER AISLADO: **42 pruebas / 112 aserciones, OK**. No acredita producción ni cierre de DATA-02/legado; el SaaS sigue **NO APTO PARA PRODUCCIÓN**. |
| INF-05: legado y supervisión sin cierre acreditado | MEDIO | Outbox no descubre ventas antiguas sin evento; el reconciliador conserva su límite 500/24 h. No hay evidencia suficiente de alertas/recuperación física en destino. | PENDIENTE; 53/768 de INF-04 no cierra el legado ni la operación. |

### INF-05/F1: integridad de la fuente confirmada

**Causa y riesgo:** los observers actuaban principalmente ante cambios de estado,
pero no existía una barrera que impidiera editar método/importes/líneas después
de confirmar. El consumidor correctamente idempotente de INF-04 conserva su
asiento; una edición posterior podía alterar la fuente que usa caja y dejar
venta/caja/contabilidad divergentes. No se investigaron datos reales ni se
afirma haber observado un incidente productivo.

**Protección implementada y validación funcional acotada descrita debajo:**

- `SaleFinancialIntegrity` relee por `business_id` e ID y utiliza
  `SELECT ... FOR UPDATE`. Antes de guardar sincroniza el original del modelo
  con la fila bloqueada, conservando únicamente las modificaciones solicitadas.
  Un modelo obsoleto no decide la autorización ni repite una transición ya
  persistida. Se rechazan cambios de identidad/empresa.
- `Sale` protege importes, impuestos/descuento, método, cobro/cambio, cliente,
  fechas, cajero, referencias y origen contable, además del borrado ordinario.
  Las notas administrativas siguen permitidas. No se rellenan números legacy
  faltantes al guardar solamente notas.
- `SaleItem` protege creación, modificación, eliminación y traslado. Bloquea
  todas las ventas involucradas en orden de ID antes de evaluar su estado y
  antes de bloquear la línea; vuelve a comprobar el vínculo persistido.
  Verifica empresa de venta/línea/producto/variante y pertenencia de líneas kit.
  Un traslado permitido entre ventas nuevas en construcción recalcula también
  el origen dentro de la transacción, no solamente el destino.
- Completada, anulada/cancelled/refunded y evidencia outbox/asiento mantienen
  la fuente cerrada. Anular y reconfirmar no permite cambiar campos financieros;
  no se permite degradar una fuente cerrada a NULL/borrador para editarla.
- `ProtectsSaleFinancialWrites` envuelve guardado/borrado y sus observers en la
  misma transacción. Funciona también con `saveQuietly`, `deleteQuietly` y
  `withoutEvents`: los eventos no son la única barrera. Un rechazo/exception o
  guardado que devuelve `false` aborta la operación.
- `SaleFinancialQueryBuilder`, creado por ambos modelos, protege también
  actualizaciones/arithmetic/borrados mediante Eloquent, `toBase()` y
  `getQuery()`. El conjunto escrito queda limitado a IDs inspeccionados y
  bloqueados, no a nuevas filas que posteriormente coincidan con el predicado.
  Normaliza nombres de campos para la comparación case-insensitive de MySQL.
  Se rechazan cambios masivos de estado, escrituras masivas de líneas sin
  modelo protegido, inserts sin modelo activo, upsert/insertOrIgnore/
  insertUsing/updateFrom/truncate/destroy y formas de escritura con joins,
  alias de tabla, agrupación o límites. Para estos casos se deben usar los
  modelos/caminos transaccionales soportados, no convertir a SQL crudo.
- `FinancialSaleItems` hace atómicos `createMany` y `saveMany`, incluyendo
  rollback de líneas y recálculo de totales si falla un integrante. Los
  traslados revisan tanto origen como destino, no solo el padre de la relación.
- `SaleService` construye mediante una capacidad acotada a la cabecera
  **recién insertada**, su empresa y conexión/prefijo, durante su callback
  transaccional. No se puede conceder a una venta existente. Se retira en
  `finally` y se sella antes de inventario/caja/contabilidad; un asiento o evento
  terminal prevalece incluso durante construcción. Estar dentro de cualquier
  transacción no concede esa capacidad.
  `SaleService` verifica el contexto de empresa antes de consultar productos
  o iniciar efectos de creación; no deriva autorización de la empresa enviada.
- La interfaz deshabilita campos financieros/líneas; `EditSale` rechaza
  payload de líneas confirmadas y usa transacción de página. La defensa de
  servidor no depende de esas restricciones visuales. La anulación en servicio
  verifica empresa y estado de la fila bloqueada antes de restaurar movimientos.

**Compatibilidad y alcance:** se conserva el proceso cabecera-antes-de-líneas,
las devoluciones por asignaciones y reembolsos manuales, y las transiciones
existentes de anulación/reconfirmación sin permiso para reescribir la fuente.
No se modificaron en F1 `SaleObserver`, `AccountingService`,
`AccountingOutboxService`, jobs, colas, secuencias o esquema. Los fixtures
financieros y de recuperación INF-04 se adaptaron para ensamblar solamente
ventas nuevas mediante la capacidad delimitada. No se cambió la valoración:
el fallback de costo cero de F6 sigue pendiente; tampoco se corrigió F2.
La repetición autorizada acredita las regresiones incluidas INF-02/INF-04,
devoluciones, resúmenes y e-CF desactivado; no una suite global ni todas las
integraciones productivas.

**Rutas no cubiertas y controles adicionales pendientes:**

- `DB::table('sales'/'sale_items')`, `DB::update/statement/unprepared`,
  PDO/cliente SQL y builders nuevos o sustituidos manualmente no pasan por la
  fábrica de consultas de los modelos. Las referencias directas encontradas
  en `app` para estas tablas son lecturas de métricas/portabilidad; esto no es
  una garantía frente a código nuevo, dinámico o clientes externos.
- Las acciones FK `CASCADE`/`SET NULL` no ejecutan estas barreras. El esquema
  existente permite cascada desde empresa/venta y SET NULL desde
  cliente/usuario/producto (`2026_07_14_120000_create_sales_tables.php`).
  El ciclo de eliminación de esas otras entidades necesita contrato separado;
  no se modificó silenciosamente en F1.
- Migraciones/importaciones/restores pueden escribir directamente. Ejemplo
  existente: `2026_07_17_100000_upgrade_sales_for_pos.php:82-85` actualiza
  líneas mediante `DB::table`; no se ejecutó ni se alteró esta migración.
- F1 no convierte el ledger, asignaciones de devolución ni el catálogo en
  snapshots inmutables, ni resuelve la carrera editor/publicación de asientos.
  Tampoco garantiza atomicidad de efectos externos arbitrarios agregados por
  nuevos callbacks ni orden global de bloqueos de transacciones ajenas.

Para una barrera independiente del proceso PHP se propone, **sin implementarla**,
identidad/estado de cierre persistido, controles de escritura y FK en MySQL
(triggers/procedimientos o permisos restringidos según diseño aprobado),
separar usuario de aplicación y mantenimiento, y revisar cascadas. Su diseño
debe admitir exclusivamente construcción nueva y transiciones autorizadas,
no un indicador de sesión libre para desbloquear históricos. Exige autorización
de esquema/permisos y pruebas adicionales. No se declara inmutabilidad SQL global.

**Archivos F1:** `app/Services/SaleFinancialIntegrity.php`,
`app/Database/SaleFinancialQueryBuilder.php`,
`app/Database/FinancialSaleItems.php`,
`app/Traits/ProtectsSaleFinancialWrites.php`, `app/Models/Sale.php`,
`app/Models/SaleItem.php`, `app/Providers/AppServiceProvider.php`,
`app/Services/SaleService.php`, `app/Filament/Resources/SaleResource.php`,
`app/Filament/Resources/SaleResource/Pages/EditSale.php`,
`tests/Unit/SaleFinancialIntegrityTest.php`,
`tests/Feature/FinancialOperationsTest.php`,
`tests/Integration/PostSaleAccountingQueueTest.php`,
`tests/Fixtures/sale-financial-concurrent-worker.php`,
`tests/Fixtures/sale-financial-preflight.php` y este informe.

**Verificaciones ejecutadas:** PHP lint en los quince PHP F1, carga de firmas
de clases/herencia mediante autoload **sin bootstrap de aplicación**, Pint
`--test` y `git diff --check`: aprobados. El primer Pint detectó formato y se
corrigió únicamente en archivos del lote. Ninguna de estas verificaciones
acredita concurrencia, rollback o aceptación de UI en runtime.

**Regresiones ejecutadas; ambas ejecuciones descritas debajo:** Unit para equivalencia decimal,
expresiones SQL y cambios de origen/estado; Feature para caminos ordinarios,
quiet/sin eventos, consultas base, borrado, inserción/traslado, aislamiento,
notas y rollback de batch; Integration para ocho operaciones competidoras
con dos procesos/conexiones MySQL independientes. Cada competidor carga el
modelo antes de reconfirmar una venta anulada, debe esperar el bloqueo
(timeout MySQL 1205), y al
reintentar debe rechazar el estado obsoleto sin cambiar fuente/asiento/líneas.
Se verifica diferencia de `CONNECTION_ID()`, rollback de construcción y
caducidad de su capacidad. Se conservaron y ejecutaron los casos INF-02/INF-04,
devoluciones, resúmenes y e-CF desactivado en la aceptación dirigida.

**Primera ejecución funcional autorizada — 2026-11-02:** runner
`81fa3099b9a7`, PHP 8.3.36/PHPUnit 11.5.57, cinco archivos dirigidos,
**97 pruebas, 915 aserciones, 12 errores**, 151.807 s, 76.50 MB.
Once errores ocurrieron durante la preparación: tres Feature y los ocho
casos de concurrencia intentaban insertar `sales.status=NULL`, pero el esquema
existente exige NOT NULL y `SaleStatus` no define borrador/pendiente. Los ocho
competidores no llegaron a ejecutar sus carreras; NO hay evidencia positiva
de concurrencia F1 en esa salida. El otro error fue un fixture de devolución
que cambiaba `amount_paid` después de confirmar; la nueva barrera lo rechazó.

**Corrección acotada posterior a la primera ejecución:** solamente
`FinancialOperationsTest.php` y `PostSaleAccountingQueueTest.php`.
No se modificó código de aplicación, enum, esquema, F2 o F6 para acomodar
los tests. Los escenarios positivos de edición/traslado y rollback de batch
se prepararon dentro de callbacks de construcción de ventas nuevas con estado
válido y observers habilitados. El rechazo de traslado desde una venta cerrada
se prueba con destino todavía en construcción, para no depender de que el
destino también esté cerrado. El importe pagado especial del fixture de
devolución se captura al insertar, nunca modificando una venta ya confirmada.
La concurrencia usa la transición existente anulada -> completada, con el
competidor cargado previamente y IDs ordenados para que el origen se bloquee
antes del destino. No se introdujo un estado financiero nuevo ni se afirmó
compatibilidad con un borrador inexistente. Los casos corregidos, incluyendo
recalcular ambos totales y rollback, se validaron en la repetición autorizada.
Pint, PHP lint de los dos archivos y `git diff --check` posteriores: aprobados.
No se repitió PHPUnit automáticamente.

**Imagen corregida y preflight renovado — 2026-11-02:** build completado,
manifest list
`sha256:f444c11db2addb008a4a417ce92143644d5821a156ac2dc2321055ee744b42e3`,
config `sha256:251eafebe1eccd65fade674266ef2af5e722f0c5086ddd39dbbe35a69e5ed9e4`.
Runner de lectura `77c45bc0b78b`: guard PASS, conexión SQL 30211, mismo
esquema aislado, host/UUID/cuenta/grants restringidos que el preflight anterior;
las dieciocho tablas requeridas InnoDB. Redis DB 15, ping true, run_id
`466f108de4bd8e146b11cd36aae425693189d360`. La cadena exigió healthy en ambos
contenedores antes del preflight. `phpunit_executed=false`,
`migrations_executed=false`. Tras este resultado el usuario autorizó una
repetición de los cinco archivos, workers y DDL acotado de copias/colas propias.

**Repetición funcional autorizada y aprobada — 2026-11-02:** runner
`05744f5ae8ae`, imagen corregida/preflight anteriores, PHP 8.3.36/PHPUnit
11.5.57. Resultado **OK (97 tests, 999 assertions)**, cero errores/fallos,
157.780 s, 76.50 MB. Archivos ejecutados:
`tests/Unit/SaleFinancialIntegrityTest.php`,
`tests/Feature/FinancialOperationsTest.php`,
`tests/Integration/PostSaleAccountingQueueTest.php`,
`tests/Feature/ScaleDailySummaryTest.php` y
`tests/Feature/EcfDisabledTest.php`.
No se suman aserciones con la ejecución fallida ni resultados históricos.

Los ocho competidores verificaron conexiones distintas, espera del bloqueo
MySQL y rechazo al reintentar tras reconfirmación, sin cambiar fuente/asiento/
líneas. Los casos Feature acreditan los caminos incluidos de guardado,
quiet/sin eventos, consultas base, edición/borrado/inserción/traslado,
aislamiento, rollback y construcción delimitada. No se ejecutó una aceptación
HTTP/Livewire completa ni controles de triggers/permisos SQL o cascadas.
Los límites anteriores permanecen vigentes. No hubo cambios funcionales
posteriores a este resultado; únicamente actualización de este informe.
Sin migraciones, seeders, suite general, despliegue o activación e-CF.

**Procedimiento de preflight nuevo, ejecutado posteriormente el 2026-11-02:** primero autorizar preparación
Docker; inspeccionar nuevamente IDs/proyecto/imágenes/estado, puertos, redes y
montajes de `test-mysql`/`test-redis` y la red interna. No reutilizar identidades
ni permisos anteriores de TLS/INF-04. Reconstruir solo la imagen `phpunit` con
los dos perfiles `testing` y `accounting-testing`, sin iniciar servicios:

**Evidencia de preparación recibida — 2026-10-31:** reconstrucción completada
(manifest list `sha256:ae155debe047c55ea03f847e6a2dd27329ef0a1915732d53c8cc11771963b95a`,
config `sha256:1347560545dd2a03407035586f42d464334aa69afe815794edb0297f35187b96`).
MySQL `ef3c89813c5c598bc5a0d301ccedaa795a2048cfdc0990fefacd65f9747f3082`
activo/healthy, sin puertos publicados, volumen
`comercios_rd_isolated_test_mysql` con labels del proyecto aislado.
Red `comercios_rd_isolated_test_backend` inspeccionada como interna, con
únicamente ese MySQL conectado. Redis
`1a271a30edc664146eb70b144785d781c16e0802976cad83d9e56fadd5d5bb6e`
inspeccionado como detenido, sin montajes persistentes ni puertos publicados.
El usuario autorizó iniciar y verificar exclusivamente ese Redis existente,
sin recrearlo ni tocar MySQL. En esa etapa quedaron pendientes inspección de
comando/tmpfs, inicio, PING/estado y preflight SQL/Redis de lectura; resultado
posterior debajo. En esa etapa PHPUnit y DDL aún no tenían autorización;
las autorizaciones dirigidas y ejecuciones posteriores constan arriba.

**Preflight F1 aprobado — 2026-11-02:** el usuario verificó Redis sin RDB
(`--save ""`), sin AOF, `/data` tmpfs 16 MiB y reinicio `no`; inició solo
el contenedor autorizado. PING respondió PONG, sin puertos publicados, red
aislada y sin montajes persistentes. Tras comprobar `healthy` en ambos IDs,
ejecutó el runner `2fc8916a1f92`: guard PASS, esquema
`comercios_rd_storage_test`, host `ef3c89813c5c`, UUID
`bfd92f30-d1c5-11f1-beb5-b24b172266ee`, MySQL 8.4.13, cuenta
`comercios_storage_test@%`, conexión 29727. Grants: USAGE global y permisos
SELECT/INSERT/UPDATE/DELETE/CREATE/DROP/REFERENCES/INDEX/ALTER únicamente
sobre el esquema de pruebas, sin GRANT OPTION. Las dieciocho tablas requeridas
se verificaron InnoDB. Redis `test-redis`, DB 15, ping true, nuevo run_id
`466f108de4bd8e146b11cd36aae425693189d360`.
Las inspecciones Docker previas complementan el script, que no inspecciona el
daemon. `phpunit_executed=false`, `migrations_executed=false`.
Esto acredita preparación aislada, no funcionamiento de F1.

```bash
sudo docker compose --profile testing --profile accounting-testing \
  --env-file .env.testing.docker -f compose.testing.yaml build phpunit
```

Solo tras verificar esa topología y disponibilidad, ejecutar el preflight de
lectura con autorización de preparación, **no PHPUnit**:

```bash
sudo docker compose --profile testing --profile accounting-testing \
  --env-file .env.testing.docker -f compose.testing.yaml \
  run --rm --no-deps -e INF05_F1_PREFLIGHT=1 phpunit-accounting \
  php tests/Fixtures/sale-financial-preflight.php
```

El script exige guard aislado, endpoints exactos, tablas/columnas requeridas,
InnoDB, identidad/grants restringidos y Redis DB 15/run_id. Si falta una tabla,
servicio o condición, detenerse: no migrar ni arrancar/recrear servicios por
inferencia. El script no comprueba por sí mismo los montajes/red del daemon.
La integración dirigida usa copias vacías con prefijo aleatorio dentro del
esquema aislado y limpia solamente sus propias tablas/colas; ese DDL de pruebas
necesita autorización explícita junto con la ejecución.

**Estado final del lote:** **INF-05/F1 CORREGIDO Y VALIDADO en el alcance
Docker aislado probado**, con los límites de escritura directa/FK y aceptación
documentados. INF-05 completo sigue pendiente y SaaS **NO APTO PARA PRODUCCIÓN**.
**Próximo paso recomendado al cerrar F1:** presentar el lote F2 de generación fiable de CxC
y obtener autorización específica antes de cambiar ese contrato; el diagnóstico
posterior figura debajo y aún no autoriza implementación. F6 y el
cierre de legado/supervisión requieren sus propios lotes. Cualquier barrera
MySQL adicional exige diseño y autorización separados. No se detuvieron
servicios al cerrar esta validación: Redis se inició con autorización y
detenerlo necesita otra; no se eliminaron contenedores o volúmenes.

**Aislamiento y autorización:** INF-02/INF-04 acreditan sus escenarios contables
multiempresa, reintentos y concurrencia; SEC-01/DATA-01 acreditan conteos.
No extrapolar esos resultados a todas las acciones HTTP/Livewire, IDOR,
documentos privados o modos de stock compartido. Los archivos privados necesitan
aceptación real de Nginx/bucket y permisos en staging. No se modificaron permisos,
inventario, pagos ni asientos en INF-06.

**Corrección INF-01 y compatibilidad:** la misma política aplica al driver
Laravel `mysql`, PDO de identidad y archivos de clientes Oracle MySQL/MariaDB.
Se comprueba cipher antes de configurar la sesión; opciones PDO que soliciten
no verificar no prevalecen. El CNF exclusivo evita overrides de `.my.cnf`, y
la validación de valores ocurre antes de crear temporales 0600. El driver
Laravel `mariadb` y otros clientes no quedan certificados por esta corrección.
La CA heredada de la conexión activa debe cubrir también el destino de restore;
un destino con otra autoridad necesita configuración autorizada, no desactivar
la verificación. El runtime Oracle requiere revisar login paths.

**Validación INF-06 disponible:** se ejecutaron consultas de avisos sin instalar
paquetes: Composer reportó el aviso MEDIO citado; npm reportó cero avisos.
Pint y PHP lint pasaron para los catorce PHP del lote; `sh -n` pasó para
los dos scripts shell. `git diff --check` pasó. Compose
testing/producción pasó `config --quiet` sin daemon ni mostrar variables;
se comprobó estáticamente red interna, ausencia de puertos y tmpfs de los
servidores nuevos. El runner TLS no hereda el perfil normal ni depende del
MySQL persistente. PHPUnit autorizado pasó con **72 pruebas/281 aserciones**,
y el check Oracle pasó sus cuatro casos. No se atribuye a este lote el
resultado histórico 53/768 de INF-04 ni se suman ejecuciones distintas.

### INF-05/F2: diagnóstico y diseño de confirmación con CxC

**Estado y alcance — 2026-11-02:** revisión estática del árbol de trabajo actual,
incluidas las protecciones F1 y los contratos INF-02/INF-04. No se ejecutó
aplicación, PHPUnit, SQL, migrador, Docker ni consultas sobre históricos.
Solo se actualiza este informe. Los números de línea describen el código
auditado, no una versión desplegada. Severidad **ALTA** por divergencia entre
venta confirmada, auxiliar de clientes y mayor; no se afirma un incidente real.

**Flujo comprobado:** POS API (`PosTerminalController::checkout` y
`PosSessionService::checkout`), Filament `CreateSale`, conversión de órdenes,
cotizaciones y receta terminan en `SaleService::create`. Este abre transacción
(`SaleService.php:115`), normaliza crédito, exige un customer_id no vacío y
fija amount_paid=0 (`:358-370`); inserta cabecera completada antes de líneas
mediante construcción F1 (`:391-420`), sella y procesa efectos (`:469-475`).
`SaleObserver::created` registra outbox pero normalmente no procesa la cabecera
sin items precargados (`SaleObserver.php:92-103`). `updated` procesa al cambiar
a completada (`:109-116`). El cobro de registros existentes
(`SaleResource.php:923-976`) guarda el modelo; F1 sigue rechazando cambios
financieros de una fuente ya cerrada.

El procesamiento ordena inventario, caja, CxC, resumen y job
(`SaleObserver.php:129-179`). CxC se escribe en **receivables/Receivable**, no
en **accounts_receivable/AccountReceivable**. Algunos widgets legacy consultan
esta segunda tabla (`DashboardMetricsService.php:159-165`); no representan la
misma fuente y no deben usarse como prueba de CxC faltante.

**Causa raíz y escenarios identificados en los caminos revisados:**

| Escenario | Evidencia estática y consecuencia |
|---|---|
| Error al crear CxC | `SaleObserver.php:200-245` captura Throwable, registra warning y retorna normalmente. Un error recuperable de sentencia SQL o excepción de modelo puede dejar que la transacción exterior confirme venta, inventario, resumen y outbox sin CxC. Quitar el catch es necesario, no suficiente. Un deadlock/desconexión puede abortar la transacción completa; no se afirma que todo error permita commit. |
| Guardado cancelado sin excepción | `:239` no verifica el booleano de save(). Un listener que devuelve false permite continuar sin fila. Es una ruta soportada por Eloquent a cubrir, no un listener cancelador identificado actualmente. |
| Módulo deshabilitado | `:203-204` retorna sin CxC. El servicio de venta no rechaza crédito por módulo. Ejemplo de configuración candidata: ferretería ofrece crédito (`PaymentMethod.php:69-75`) pero sus módulos predeterminados no incluyen receivables (`Business.php:241-247`). No se inspeccionó la configuración efectiva de ningún negocio. |
| Cliente ausente o incorrecto | `:191-197` solo avisa si falta cliente. SaleService/POS validan presencia, no existencia activa y pertenencia del cliente. El lookup de nombre usa ID sin business_id (`:220`); las FK simples no exigen misma empresa. Crear una venta por otra vía puede omitir el chequeo del servicio. No se afirma un IDOR HTTP demostrado. |
| Cabecera por Eloquent fuera del coordinador | Sale::create con estado completada, sin relación items cargada, solo registra outbox; created no genera CxC. F1 protege la inmutabilidad, no exige esta obligación. `saveQuietly`/`withoutEvents` en creación o reconfirmación omiten el observer; el wrapper F1 no comprueba CxC (`SaleFinancialIntegrity.php:89-158`). La confirmación masiva mediante el builder F1 ya está rechazada: no es una nueva vía permitida. |
| Falso positivo de deduplicación | `SaleObserver.php:207-216` considera suficiente cualquier CxC con business_id/reference coincidentes. Una deuda manual con esa referencia, cliente/importe distintos o un vínculo textual ambiguo puede suprimir la CxC correcta. No verifica origen por sale_id, estado ni consistencia. |
| Duplicados y soft delete | La migración de receivables no tiene sale_id ni unique de origen (`2026_07_17_142920...php:15-49`). exists+insert no es atómico. F1 serializa los saves ordinarios sobre una misma venta, pero el método público processCompletedSale no toma por sí mismo ese lock/transacción. Dos invocaciones directas pueden competir. SoftDeletes oculta la deuda borrada y un reintento puede crear otra. |
| CxC eliminada o desvinculada después | El recurso permite editar referencia/cliente/monto y borrar individualmente/en lote (`ReceivableResource.php:71-116,253-264`; `EditReceivable.php:47`). El modelo no protege el origen. Una venta confirmada puede perder su CxC activa o quedar asociada por texto a una deuda alterada. |
| Desfase de precisión | sales.total es DECIMAL(14,2), pero receivables.amount/paid_amount/balance son DECIMAL(12,2). Un total válido en sales puede desbordar CxC en modo estricto (`config/database.php:66`), activar el catch y continuar. Configuración/motores reales pendientes de preflight. |
| Contabilidad no detecta la ausencia | `AccountingService.php:76-251,380-396` debita 1201 por método crédito, sin consultar Receivable. Job/outbox solo recuperan el asiento. El reconciliador (`ReconcilePostSaleSideEffects.php:25-85`) no crea ni verifica CxC. Reintentar INF-04 no repara este defecto. |
| Respuesta perdida/reintento del checkout | `PosSessionService.php:396-400` crea venta y después marca cotización; `SaleService.php:81-90` convierte orden fuera de una transacción envolvente propia. Puede haber commit y fallo posterior. Cliente JS envía de nuevo sin clave estable (`public/js/pos-terminal.js:408-485`); busy solo protege la pestaña en curso. Puede nacer otra venta/deuda, aunque se corrija la unicidad por venta. |
| Escritura fuera de los modelos | DB::table, SQL/PDO directo, builders reemplazados y acciones de mantenimiento eluden barreras PHP. Los archivos revisados no acreditan una barrera SQL global. Cascadas/SET NULL de FK también pueden alterar vínculos sin eventos. |

No es un inventario de datos históricos ni una demostración dinámica de cada
intercalado. Una excepción propagada por el flujo ordinario ya puede provocar
rollback gracias a F1/SaleService; el problema es tratar la obligación CxC como
opcional y carecer de identidad/postcondición compartidas.

**Diseño para el siguiente lote autorizado al cerrar el diagnóstico; NO implementado:**

1. **Invariante de confirmación.** Una nueva venta completada a crédito se
   confirma únicamente con una CxC activa, identificada por business_id/sale_id,
   del mismo cliente y moneda implícita actual RD$, con principal inicial igual
   al total definitivo, paid_amount=0 y balance inicial igual a ese total.
   Total cero sigue teniendo vínculo único y saldo cero; no introducir pagos
   mixtos, anticipos ni reglas nuevas de límite de crédito en F2. Comparar
   importes a escala decimal/céntimos, no con tolerancias float.
2. **Coordinador síncrono y fail-closed.** Extraer la obligación a un servicio
   delimitado de CxC de venta, invocado por el coordinador de confirmación.
   Usar exactamente la misma conexión MySQL y transacción de cabecera,
   líneas, inventario, resumen y outbox; comprobar también save()===false.
   Error de validación/SQL/modelo se propaga: no log-and-continue ni creación
   CxC después del commit o por cola. Registrar contexto y relanzar sin datos
   personales. Releer venta, cliente y CxC persistidos por business_id/ID.
3. **Construcción compatible con F1, no bypass transaccional.** Reservar antes
   del save de cabecera un contexto interno para una venta NUEVA concreta,
   ID/empresa/conexión, imposible de aplicar a una existente. Hoy F1 registra
   la capacidad después de insertar: hay que ajustar deliberadamente ese
   punto, no añadir un check prematuro que rompa cabecera antes de líneas.
   Al finalizar líneas/recalcular totales, sellar, generar/verificar CxC y
   comprobar la postcondición antes de permitir salir del coordinador.
   El cierre de construct debe verificarla incluso si un callback omite
   processCompletedSale. Liberar contexto en finally/rollback. Ninguna
   excepción se habilita por transactionLevel()>0.
4. **No depender del observer.** El wrapper externo a eventos debe rechazar
   creación/reconfirmación a crédito fuera del coordinador autorizado, también
   quiet/withoutEvents. Para transiciones existentes releer/bloquear estado y
   comprobar origen antes de aceptar; una fila legacy ambigua requiere revisión,
   no adopción por referencia. processCompletedSale no debe ser un replay libre
   de efectos de crédito: entrada directa sin contexto se rechaza o pasa por el
   coordinador y sus verificaciones. El servicio idempotente CxC no repite stock,
   caja ni bump de sales_daily. Conservar bloqueos del builder F1 y escrituras
   administrativas no financieras; cancelar no se trata como nuevo crédito.
5. **Identidad y esquema, aprobación separada.** Proponer migración aditiva:
   receivables.sale_id nullable para deudas manuales/legacy, UNIQUE
   (business_id,sale_id), conservando la unicidad incluso con soft delete.
   FK compuesta (business_id,sale_id) hacia sales(business_id,id), con índice
   único explícito en el padre compatible con MySQL 8.4 y borrado RESTRICT,
   sin cascade/SET NULL del nuevo vínculo. No hacer unique(reference):
   referencias manuales pueden repetirse. Alinear precisión de los tres
   importes CxC con sales.total. Snapshot original_amount inmutable para deudas
   nuevas vinculadas, separado de amount ajustable por devolución autorizada.
   NULL en legacy sin backfill, adopción o deduplicación automáticos.
   La FK garantiza el padre/tenant, no por sí sola que toda venta tenga hijo.
6. **Idempotencia con dos identidades.** Para la misma venta, bloquearla y
   buscar CxC con withTrashed; retornar solo el origen válido, sin sobrescribir
   pagos, vencimiento ni devoluciones. Una deuda eliminada/incompatible causa
   rechazo, no recreación. Para repetir checkout tras commit incierto, añadir
   clave estable de solicitud y hash canónico del comando, con unicidad por
   business_id en sales; NULL para legacy. Mismo comando/clave devuelve la venta
   original sin efectos; payload distinto se rechaza como conflicto. Resolver
   replays antes de validar stock o precios actuales, conservando autorización
   actual para acceder al resultado, incluida sucursal. Hash del comando de
   entrada normalizado, sin campos ignorados ni precios recalculados durante
   el replay; el resultado financiero efectivo se conserva con la venta.
   Clave/hash/versión se protegen como metadatos inmutables de origen también
   frente al builder. La clave la conservan
   UI/API y callers directos hasta respuesta inequívoca; no generar otra en
   cada intento. Unicidad se reclama al insertar cabecera; perdedor hace rollback
   completo, relee en transacción nueva y valida hash. Solo manejar como replay
   el conflicto de ESE índice, no cualquier 1062.
7. **Concurrencia y orden.** Para una venta existente, venta bloqueada primero,
   después evidencia outbox/asiento en el orden F1, luego CxC y sus pagos/
   ajustes; ventas múltiples en orden de ID. No tomar CxC y después venta.
   En nuevas ventas conservar locks de productos necesarios del checkout y
   ordenar IDs dentro de cada conjunto; el registro nuevo de venta aún no es
   visible a competidores. Definir explícitamente el orden de cliente/origen
   cotización/orden antes de integrar sus locks y no adquirirlos en orden
   inverso desde cobros/devoluciones. La protección se apoya en fila existente
   + índice único, no en un gap lock sobre una fila CxC inexistente.
   1213/1205: abortar la unidad completa y reintentar acotadamente con la misma
   clave/modelos recargados, nunca solo el INSERT fallido ni una transacción
   exterior parcialmente ejecutada. Una colisión del generador MAX+1 de número
   de venta no equivale a idempotencia; fallo completo/reintento acotado separado.
8. **Origen de conversiones.** Envolver lectura/bloqueo de orden/cotización,
   creación de venta y converted_sale_id en una sola transacción. Releer el
   vínculo bajo lock y retornar la venta ya convertida si coincide el comando;
   rechazar una conversión incompatible. Proteger también dos claves distintas
   para el mismo origen, no solo el doble clic con una clave. No crear una
   segunda venta antes de marcar la conversión ni rehusar ciegamente un replay
   válido porque el origen ahora está convertido.
9. **Multiempresa y módulos.** Validar contexto autorizado ANTES de usar
   TenantContext::run, y después existencia/pertenencia de cliente, venta y CxC;
   nunca confiar en relaciones cargadas ni en el business_id del payload.
   Política aprobada para el siguiente lote: rechazar nuevas ventas a crédito si
   receivables no está habilitado, mostrando error y ocultando la opción de UI.
   La alternativa de crear siempre CxC separando el permiso de UI del módulo
   no fue seleccionada. No confirmar sin deuda. No habilitar módulos
   automáticamente ni modificar configuraciones de negocios existentes.
10. **Outbox/ledger.** Mantener evento único por empresa/venta, leases,
    recuperación y asiento+processed atómicos de INF-04. En nuevas ventas
    versionadas F2, exigir el vínculo CxC antes de contabilizar y de dar por
    válido un asiento legado por referencia; guardar versión explícita del
    contrato en sales dentro de la migración propuesta, no inferirla por fecha.
    Consumidor verifica, NO crea ni repara CxC. Mantener Sale -> Outbox -> CxC
    antes de locks del mayor cuando sea necesario; no buscar venta desde CxC
    ya bloqueada. Un fallo Redis posterior al commit conserva venta+CxC+outbox
    y NO induce rollback ficticio/repetición del checkout. Contrato inline
    conserva rollback de todo ante fallo contable.
11. **Persistencia del vínculo.** Proteger en modelo/builder de Receivable
    identidad, empresa, cliente, principal original y borrados/traslados de CxC
    vinculadas, incluidos quiet, relaciones y lotes; no basta con esconder botones.
    Deudas manuales mantienen su contrato. Devolución busca por sale_id,
    bloquea fila y ajusta solo amount/balance mediante operación autorizada.
    Reentry no restablece original_amount/amount ni perdona pagos; no exigir
    amount actual == sale.total después de devolver. No habilitar edición de
    venta confirmada para corregir su deuda.
12. **Legado y límite SQL.** Sin cambios históricos. Referencias antiguas se
    consultan solo mediante camino legacy explícito; cero/varias coincidencias
    o discrepancias se señalan, nunca se adopta una fila manual automáticamente.
    Unicidad/FK no impiden borrar un hijo ni confirmar ventas por SQL directo.
    Para una garantía universal hace falta un contrato DB adicional
    (roles mínimos, procedimientos de confirmación y escritura restringida,
    revisión de FK y, si procede, triggers/estado de sellado persistido).
    MySQL no tiene constraints diferibles para imponer ingenuamente un ciclo
    venta/CxC mientras se construyen cabecera/líneas. Ese endurecimiento
    necesita diseño/autorización propios; no usar variables de sesión libres.

**Devoluciones, cancelación y saldos: límites importantes.**
`SaleService::applyRefund` hoy busca CxC por texto, no bloquea esa fila y, si no
la encuentra, devuelve credit_no_receivable sin abortar (`:773-807`).
F2 debe usar vínculo fuerte para ventas nuevas y fallar con rollback de la
devolución si falta la deuda obligatoria; conservar reducción parcial y aviso
por pagos superiores al principal restante. No crear otra CxC en un retry.

La cancelación actual restaura inventario y cambia estado (`:815-845`);
el observer revierte caja/resumen, **no ajusta CxC ni revierte asiento**.
No afirmar que esa liquidación está resuelta. F2 conserva vínculo, pagos y
evidencia y no los elimina/recrea al cancelar/reconfirmar. Definir una
liquidación/reversión automática nueva requeriría aprobación funcional aparte.
La atomicidad/concurrencia completa de cobros, su anulación y caja sigue en
DATA-02 (`Receivable.php:83-156`, `ReceivablePayment.php:60-66`,
`PaymentsRelationManager.php:81-98`). El usuario autorizó para el siguiente lote
la dependencia acotada DATA-02: protocolo transaccional común para cobros, su
anulación y devoluciones de CxC vinculadas. Aún no está implementado.
No prometer que añadir
un lock únicamente en devoluciones resuelve un cobro que usa saldo obsoleto.
F6/costos históricos y cuentas por pagar quedan fuera.

**Archivos afectados por la futura implementación (ninguno cambiado ahora):**

| Grupo | Alcance previsto |
|---|---|
| Confirmación | SaleService, SaleObserver, SaleFinancialIntegrity, ProtectsSaleFinancialWrites y SaleFinancialQueryBuilder; Sale y nuevo servicio delimitado de CxC. Mantener reglas F1. |
| Deuda y permisos | Receivable y su builder/guard de escritura; ReceivableResource y EditReceivable para errores y bloqueo de edición/borrado vinculado. ReceivablePayment/PaymentsRelationManager incluidos en la dependencia DATA-02 autorizada para el siguiente lote. |
| Entradas/replays | PosSessionService, PosTerminalController, public/js/pos-terminal.js, CreateSale y callers de crédito/conversión: QuoteService, convertFromOrder/OrderResource y ViewPrescription cuando corresponda. Estado de solicitud estable, sin rediseño visual. |
| Contabilidad | AccountingService para comprobación versionada CxC; conservar contratos de AccountingOutboxService y PostSaleAccountingJob, sin cambios de leases/presupuestos/colas salvo necesidad demostrada. |
| Esquema propuesto | Migración aditiva nueva para vínculo/precisión/snapshot de CxC y clave/hash/versión de contrato en sales. No editar/rejecutar migraciones históricas; aplicación necesita autorización de DDL separada y preflight de esquema. |
| Pruebas | Unit nuevo de contrato/idempotencia; FinancialOperationsTest, PosApiTest y pruebas dirigidas de Filament; PostSaleAccountingQueueTest o integración F2 delimitada, worker competidor y preflight F2. Actualizar fixtures válidos sin relajar F1. |

**Riesgos del cambio:** romper cabecera antes de líneas con un check prematuro;
registrar dos veces CxC/stock/resumen desde observer y servicio; tratar una
referencia manual como identidad; reabrir deudas pagadas/devueltas; bloquear
replays por stock/precios ahora distintos; mezclar conexiones; ampliar a DATA-02
sin permiso; incompatibilidad con fixtures que omiten eventos; locks inversos;
aplicación sin esquema nuevo; y semántica comercial de módulos/cancelación.
El DDL MySQL puede bloquear e implicar commit: nunca ejecutarlo dentro de una
transacción de venta ni incluirlo en un despliegue no autorizado.

**Pruebas diseñadas, NO preparadas en código ni ejecutadas:**

| Nivel | Casos que deben fallar con el defecto actual / verificar el nuevo contrato |
|---|---|
| Unit | credito/credit/alias fiado, cálculo exacto de principal y cero, hash canónico estable y conflicto de clave, validación de identidad y resultados CxC; no confundir importe ajustado con principal original. No usar mocks como prueba de atomicidad SQL. |
| Feature MySQL | Crear crédito por servicio/API/Filament y conversiones: una venta+líneas+CxC+outbox del mismo tenant. Falta de cliente, cliente ajeno/inexistente/eliminado, módulo según política y guardado false: rechazo, sin efectos. Modelos stale y quiet/withoutEvents fuera del coordinador no eluden obligación, incluso si se omite status y se aplica el default de MySQL. |
| Feature MySQL | Inyectar error SQL real al insertar CxC (restricción sintética en fixture propia), excepción de modelo y fallo después de CxC/antes del cierre. Afirmar rollback de cabecera/líneas, movimientos/stock/lotes, CxC, resumen, outbox y jobs; con rollback exterior y modo inline también asiento/líneas. No provocar el error insertando datos inválidos en históricos. Verificar límites de precisión. |
| Feature MySQL | Referencia manual coincidente no suprime CxC; idempotencia no sobrescribe pagos/devoluciones/vencimiento. Borrado lógico/físico/masivo, relink, cambio financiero quiet y relaciones de deuda vinculada rechazados; deudas manuales no cambian de contrato. |
| Feature UI/API | Repetir misma clave devuelve mismo sale_id; clave con payload diferente no altera nada; respuesta perdida después del commit y cambio posterior de stock/precio no crean otra venta. Confirmación exitosa solo tras CxC; error visible sin falso ticket de éxito. Claves iguales en empresas diferentes independientes. |
| Feature finanzas | Devolución parcial/total nueva usa sale_id y preserva pagos; falta de CxC revierte devolución; retry no resta dos veces; cancelación conserva evidencia sin crear deuda duplicada; reconfirmación valida vínculo. Legacy ambiguo falla sin reparación automática. |
| Integration dos conexiones reales | Dos intentos de asegurar CxC sobre la misma venta: demostrar bloqueo con barreras/CONNECTION_ID distintos, commit y rollback del primero, una sola fila al final. Probar también el índice único mediante insert concurrente controlado, no solo serialización PHP. |
| Integration dos conexiones reales | Checkout doble con misma clave/hash; mismo token con hash diferente; dos tokens para la misma orden/cotización; números de venta en colisión; empresas distintas; confirmación versus borrado/alteración de deuda. Timeout/deadlock: toda unidad vuelve a cero, retry acotado seguro. No usar sleeps como única sincronización. |
| Integration interrupciones | Matar solo worker/proceso propio antes de insertar CxC, después de CxC antes del commit y después del commit antes de responder/publicar. Segunda conexión comprueba todo-o-nada y replay devuelve original. No detener MySQL/Redis compartidos para simular caída. |
| Integration cola/outbox | Redis inaccesible tras commit: venta+CxC+outbox durables, recuperación publica y procesa una vez; entrega duplicada, muerte del worker, error contable y retry no recrean CxC. Asiento crédito 1201 y processed juntos; modo inline falla con rollback total. Venta F2 sin CxC inconsistente se rechaza al contabilizar, sin reparar ni marcar processed. |
| Dependencia DATA-02 autorizada | Devolución versus cobro/anulación de pago y dos cobros: todos deben usar lock/lectura fresca común. Validar saldo, pago, ajuste y caja bajo el protocolo aprobado para CxC vinculadas; no extrapolar a deudas manuales o cuentas por pagar. |

**Preflight Docker para el lote futuro:** renovar identidad/grants/UUID,
red interna sin puertos, volumen exclusivo, salud MySQL/Redis, DB Redis 15 e
imagen de código autorizada. El preflight F1 verifica 18 tablas pero no
customers/receivables/receivable_payments
(`tests/Fixtures/sale-financial-preflight.php:28-33`): ampliarlo y verificar
motores InnoDB, columnas, precisión, índices y FK reales del contrato F2.
No asumir que el PASS de F1 acredita este esquema ni el estado Docker actual.

La integración actual copia tablas con CREATE TABLE LIKE
(`PostSaleAccountingQueueTest.php:87-98`), que no copia FK; además no incluye
clientes/CxC/pagos. Preparar copias vacías propias completas y DDL explícito
de FK apuntando SOLO a padres de ese mismo prefijo cuando se prueben
restricciones; inspeccionarlas y probar rechazo real de referencia ajena.
No desactivar FK para fingir éxito ni permitir que una copia referencie
tablas originales. Workers, tablas y colas con prefijo aleatorio; limpieza
solo de sus recursos propios y con autorización. No migrador general,
seeders, down/prune, borrado de volúmenes o recursos existentes.

**Criterios de aceptación antes de declarar F2 validado:**

- Para cada crédito nuevo exitoso hay exactamente una CxC activa con
  empresa/venta/cliente/principal correctos; una confirmación rechazada deja
  cero efectos nuevos. Verificación desde otra conexión después del commit,
  no solo del modelo en memoria.
- Unicidad de origen y solicitud comprobadas en MySQL 8.4; reintentos tras
  éxito desconocido no duplican venta, deuda, stock, resumen, evento ni asiento.
  Errores SQL/modelo, rollback exterior y muerte de proceso cumplen todo-o-nada.
- Ninguna escritura de modelo, quiet, relación o lote soportado elude la
  confirmación o elimina/desvincula CxC nueva. Límites SQL directo/FK y legado
  siguen explícitos; no afirmar garantía universal sin control DB adicional.
- Preservar pruebas F1 (incluidos ocho competidores), INF-02/INF-04, resúmenes,
  e-CF desactivado y devoluciones/cancelación según contrato aprobado, más
  nuevas Unit/Feature/Integration y UI/API. No fijar de antemano un conteo de
  pruebas como sustituto de cubrir estos invariantes.
- Evidencia reproducible de imagen, preflight renovado, restricciones reales,
  conexiones independientes, resultados y limpieza acotada; sin modificación
  histórica, credenciales, despliegue ni activación e-CF. La política de módulos
  y la dependencia DATA-02 aprobadas debajo deben respetarse.

**Autorización explícita registrada al cerrar esta fase:** el usuario seleccionó
“Autorizar siguiente lote F2 y preparación de pruebas/esquema, sin implementarlo
en esta fase”; “Rechazar crédito sin módulo CxC; no habilitar módulos
automáticamente”; e “Incluir protocolo transaccional común para cobros, su
anulación y devoluciones de CxC vinculadas”. Autoriza el siguiente lote de código
y preparación de pruebas/migración como archivo, no implementación durante
este diagnóstico. No incluye cuentas por pagar, F6, reparación histórica ni una
nueva liquidación/reversión automática al cancelar una venta.

Aplicar DDL al esquema aislado, construir/iniciar recursos Docker y ejecutar
pruebas requieren aprobaciones posteriores específicas y preflight renovado.
En esta fase solo se modificó el informe; revisión de espacios en blanco
aprobada, incluido el archivo aún no versionado.
**F2 NO IMPLEMENTADO NI VALIDADO**, INF-05 incompleto y SaaS
**NO APTO PARA PRODUCCIÓN**.

### INF-05/F2: implementación posterior al diagnóstico — pendiente de validación

**Autorización y alcance vigentes.** La solicitud posterior autorizó código,
preparación de migraciones/pruebas y verificaciones estáticas. Reemplazó el
alcance futuro amplio de DATA-02 por **preparar el protocolo compartido e
implementar solo dependencias estrictas F2**. No autoriza DDL, PHPUnit,
escrituras operativas, backfill, credenciales, despliegue o activación e-CF.
El diagnóstico anterior conserva su estado histórico; no describe el estado
actual del código.

**Causa raíz corregida en código.** Se eliminó del observer la creación
best-effort que retornaba sin cliente/módulo, adoptaba deudas por referencia
textual, ignoraba `save() === false` o capturaba la excepción y continuaba.
No se han inspeccionado ni reparado ventas/deudas históricas.

#### Contrato implementado

- `SaleFinancialIntegrity::construct` delimita la construcción antes del
  guardado inicial. Crédito directo fuera de este coordinador, incluso quiet
  o `withoutEvents`, se rechaza. Antes de salir exige líneas persistidas,
  CxC válida y procesamiento de efectos dentro de la misma conexión MySQL.
  Los guardados rechazados y excepciones se propagan para rollback exterior.
- `SaleCreditService` comprueba negocio activo, módulo CxC, cliente activo de
  la empresa y autor/permisos cuando hay usuario. No habilita módulos.
  Sella una CxC por `(business_id, sale_id)`, con principal original exacto en
  centavos, saldo inicial completo y cobrado cero. Busca también soft-deleted:
  no recrea ni adopta una deuda borrada, manual o de otro origen.
- `credit_version = 1` distingue las nuevas fuentes. `credit_effects_at`,
  protegido frente a escrituras ordinarias, evita repetir inventario, resumen
  y despacho al invocar de nuevo el observer. Confirmación inicial guarda
  venta, líneas, CxC y outbox en una unidad SQL. Redis se publica después del
  commit; su fallo conserva intención/deuda y no vuelve a cobrar.
- `SaleCheckout` reclama **antes de los efectos** la identidad en
  `sale_checkout_requests`, con unicidad por empresa/clave y hash canónico
  del comando/actor. La fila y su enlace a la venta se confirman juntos.
  Replay compatible devuelve la venta original antes de volver a validar
  catálogo, stock o precios. Payload/actor incompatible se rechaza.
  Reintentos transaccionales del checkout raíz: máximo tres ante errores de
  concurrencia reconocidos por Laravel; dentro de transacción exterior, uno.
- Se eligió una tabla de solicitudes en lugar de columnas de clave/hash en
  `sales`: permite arbitrar antes de reservar productos o crear cabecera.
  No almacena el payload completo ni elimina identidades automáticamente.
  Una clave distinta sigue siendo una solicitud distinta: no se promete
  deduplicación semántica entre UUID diferentes.
- POS, alta Filament, dispensación de receta y conversiones de orden/cotización
  envían identidad estable. Las conversiones bloquean/releen su origen y
  comprueban el guardado del enlace dentro de la transacción. POS conserva una
  copia independiente del comando en `sessionStorage` por empresa/cajero:
  errores de red/resultado desconocido reenvían el original, aunque cambie el
  carrito. Un rechazo conocido permite corregir datos **con la misma clave**,
  nunca con otra que pudiera duplicar una solicitud concurrente ya confirmada.
  Si el almacenamiento falla, no se permite iniciar otro cobro.
- `SaleReceivableIntegrity`, el modelo y su builder protegen identidad,
  cliente, principal, cobrado/saldo/estado y borrado vinculado también sin
  eventos. Los lotes y `toBase()` no pueden eludir la barrera; inserciones con
  columnas en mayúsculas y rutas JSON se validan/rechazan. Capacidades
  internas separadas permiten ajuste autorizado por devolución y recálculo
  desde pagos de la misma empresa. Eliminación múltiple de modelos es
  transaccional para no dejar un lote parcialmente borrado.
  El bloqueo interno del origen no impone visibilidad de sucursal de ventas a
  una CxC compartida por empresa; las entradas de venta conservan su propio
  scope/autorización. No se convierte CxC en una entidad limitada por sucursal.
- El consumidor contable exige CxC para fuentes versionadas antes de crear
  o adoptar un asiento; no repara deuda ausente. INF-02/INF-04 conservan
  publicación, leases, presupuestos y recuperación. El modo inline permanece
  dentro de la unidad SQL y un fallo contable debe abortarla.
- Filament no permite editar identidad/principal ni eliminar la deuda
  vinculada. Sus campos derivados no se reenvían al guardar notas.
  Las opciones de crédito se ocultan sin módulo, pero el backend decide.

**Cambios justificados sobre componentes validados.** F1 necesita exigir la
postcondición CxC fuera de eventos y controlar el ciclo de confirmación;
observer/servicio y consumidor deben participar en la unidad transaccional.
`SalesDailySummaryService` ahora respeta el prefijo SQL de la conexión,
evitando que un UPDATE de integración apunte fuera de sus copias propias.
Solo crédito versionado propaga errores SQL no-duplicados y agotamiento de
reintentos del resumen; no se sustituye su política para efectivo/legado.
Estas modificaciones requieren repetir las regresiones: el resultado
histórico F1 no demuestra compatibilidad del código nuevo.

#### Archivos del lote

| Grupo | Archivos |
|---|---|
| Nuevos servicios/guard | `app/Services/SaleCheckout.php`, `SaleCreditService.php`, `SaleReceivableIntegrity.php`; `app/Database/ReceivableQueryBuilder.php`. |
| Coordinación y consumidores | `app/Services/SaleFinancialIntegrity.php`, `SaleService.php`, `AccountingService.php`, `SalesDailySummaryService.php`, `PosSessionService.php`, `QuoteService.php`; `app/Observers/SaleObserver.php`; `app/Providers/AppServiceProvider.php`. |
| Modelos | `app/Models/Sale.php`, `Receivable.php`. |
| Entradas/UI | `app/Http/Controllers/PosTerminalController.php`; `public/js/pos-terminal.js`; `app/Filament/Resources/SaleResource.php`, `SaleResource/Pages/CreateSale.php`, `ReceivableResource.php`, `PrescriptionResource/Pages/ViewPrescription.php`. |
| Esquema preparado | `database/migrations/2026_11_02_120000_add_sale_credit_integrity.php`. |
| Pruebas nuevas | `tests/Unit/SaleCreditContractTest.php`; `tests/Feature/SaleCreditIntegrityTest.php`; `tests/Fixtures/sale-credit-concurrent-worker.php`, `sale-credit-preflight.php`. |
| Regresiones adaptadas/ampliadas | `tests/Feature/FinancialOperationsTest.php`, `PosApiTest.php`; `tests/Integration/PostSaleAccountingQueueTest.php`. |
| Informe | `PRODUCTION_READINESS_AUDIT.md`. Cambios previos del worktree se conservaron; esta lista no atribuye todo el diff histórico a F2. |

#### Migración preparada y posteriormente aplicada solo al esquema aislado

En la preparación no se ejecutó; la aplicación autorizada y su evidencia
de esquema a las 14:23 se registran en la cronología posterior.

Añade versión/marcador a `sales`, índice único `(business_id,id)`,
`receivables.sale_id`/`original_amount`, unicidad empresarial por venta y FK
compuesta con `RESTRICT`. CHECK exige ambos campos de origen nulos o ambos
presentes y principal no negativo. Amplía importe/cobrado/saldo de DECIMAL(12,2)
a DECIMAL(14,2), sin redondeo/backfill ni cambio de valores históricos.
Añade solicitudes InnoDB con clave ASCII sensible a mayúsculas, hash, identidad
por empresa y FK empresarial a la venta. `down()` rechaza eliminación automática.

Compatibilidad histórica **no verificada en datos**: el legado queda con
origen/versión NULL y no se vincula por texto. Índices/columnas preexistentes,
collations/tipos incompatibles o DDL parcialmente aplicado requieren revisión
manual; no se corrigen ni se omiten automáticamente. MySQL hace commits
implícitos de DDL: el archivo no garantiza rollback de una aplicación parcial.
La ampliación de columnas puede reconstruir/bloquear tablas; tiempos y
capacidad no están medidos. **No desplegar este código sobre esquema antiguo**:
primero se requiere aprobación y validación del esquema, sin migrador general.

#### Protocolo DATA-02 preparado, no cerrado

Para documentos existentes, el orden compartido a adoptar es:
`Sale -> AccountingOutbox -> Receivable -> pagos ordenados por id -> caja/asiento`.
Siempre filtrar origen e identidades por `business_id`; para varios documentos,
ordenar IDs antes de bloquear. Para checkout nuevo: reclamar solicitud primero,
bloquear origen de conversión si existe, validar negocio/cliente bajo bloqueo
compartido y reservar productos antes de insertar/sellar la nueva venta.
No abrir un protocolo de cobro reteniendo primero el pago o la CxC para luego
pedir la venta. La creación inicial del outbox pertenece a esa misma unidad.

El futuro cobro/anulación deberá releer y recalcular desde pagos bajo esos
bloqueos, rechazar exceso, usar identidad persistente por operación, comprobar
guardados y hacer pago/saldo/caja/asiento atómicos. Ante deadlock, repetir la
operación raíz completa con la misma identidad; nunca sumar caja por separado
después de una respuesta perdida. Devolución reduce principal ajustado, no el
snapshot original, conservando el caso ya pagado en exceso para revisión.

F2 adopta los bloqueos de fuente/CxC y el ajuste/recálculo vinculados necesarios,
pero **`registerPayment`, inserción/anulación del pago y caja todavía no forman
una única unidad con ese orden**. No se ha eliminado el sobrepago concurrente;
no se implementaron solicitudes idempotentes de cobro/pago ni se modificó
`Payable`. DATA-02 sigue PENDIENTE y sus carreras se probarán en otro lote.

Cancelación autorizada conserva stock/caja/resumen según el contrato previo y
la CxC vinculada; no inventa liquidación de deuda ni reversión contable
automática. Reconfirmar reutiliza la misma deuda, sin recrearla ni restaurar
principal ya devuelto/cobrado. No se certifica un nuevo ciclo completo de
cancelación/reconfirmación financiera. F6 y el fallback de costo siguen pendientes.

#### Pruebas preparadas, NO ejecutadas

| Nivel | Casos preparados |
|---|---|
| Unit | Canonicalización y cambios relevantes del comando; principal exacto y límites DECIMAL; importes inválidos y números no finitos. |
| Feature | Crédito válido/replay tras cambio de catálogo; mismo UUID incompatible; módulo deshabilitado; cliente ausente, inexistente, ajeno, inactivo o borrado; actor/permisos/membresía; separación por empresa. |
| Feature transaccional | Cabecera/línea/CxC/outbox con guardado false; error FK real 1452; fallo después de insertar CxC/resumen; rollback exterior; reintento de la misma clave tras rechazo; éxito y fallo inline. |
| Feature barreras/UI | Construcción sin observer; intentos quiet/base/relación, JSON y mayúsculas; no adoptar deuda por texto; devolución parcial/total, pagos y cancelación sin borrar origen; CxC compartida sin ampliar visibilidad de ventas de otra sucursal; contabilidad rechaza deuda ausente; alta Filament atómica y edición de notas sin modificar principal; POS/API duplicado y módulo deshabilitado. |
| Integration MySQL | Tres contenciones sincronizadas entre conexiones con `CONNECTION_ID()` diferentes: misma solicitud, payload incompatible y duplicado de CxC mediante INSERT SQL directo. Primero se exige timeout real 1205 bajo el bloqueo y después se comprueba replay/conflicto/unicidad 1062 tras commit. No son pruebas secuenciales presentadas como concurrencia. |
| Integration interrupción | Proceso propio detenido con SIGKILL después de CxC/resumen pero antes del commit, y después del commit antes de devolver respuesta. Reintento con el mismo UUID y verificación de una venta/deuda/efectos/asiento. |
| Integration cola | Error simulado al publicar a Redis, conservando venta/CxC/outbox; recuperación y worker real, entrega duplicada y débito 1201 una sola vez. No se detiene Redis compartido para simular el fallo. |

La integración copia únicamente tablas vacías propias con prefijo aleatorio;
añade FK explícitas entre esas copias porque `CREATE TABLE LIKE` no las copia.
La ejecución futura incluye crear/eliminar **solo esas copias nuevas**,
colas con nombre propio y procesos propios: requiere autorización que cubra
esas escrituras/limpieza, nunca borrado de tablas históricas, contenedores o
volúmenes. Se mantienen los ocho competidores F1 previos y deben repetirse.

El preflight nuevo exige `INF05_F2_PREFLIGHT=1`. Sin otra bandera es
**baseline de solo lectura**, no aprobación del esquema F2. Con
`INF05_F2_SCHEMA=1` exige además columnas/nullabilidad/precisión, índices únicos,
FK compuestas y reglas RESTRICT, CHECK aplicado y motores del contrato.
En ambas fases verifica guard, grants, MySQL 8.4 y Redis DB 15; no aplica DDL
ni ejecuta PHPUnit. La topología Docker requiere inspección separada.

**Validación realizada:** `php -l` y `vendor/bin/pint --test` aprobados en los
27 PHP del lote; `node --check public/js/pos-terminal.js` aprobado. Revisión
estática de coordinación, vías quiet/builder, índices, colas, callbacks y
entradas; controles de espacios en blanco sin errores. No equivalen a ejecución
funcional. No se arrancó Laravel ni se ejecutaron consultas de negocio.
El intento `docker inspect` de solo lectura sobre los dos contenedores
aislados conocidos terminó con `permission denied` en `/var/run/docker.sock`.
No se alteraron permisos; el preflight permanece sin ejecutar.

**Autorización operativa posterior registrada:** el usuario seleccionó
“Autorizar imagen testing, inspección y preflight baseline únicamente”.
Autoriza esa primera etapa con un runner propio, no DDL, PHPUnit,
inicio/detención o eliminación de servicios/volúmenes existentes.
El intento de inspección con `sudo -n docker inspect` terminó con
`sudo: a password is required` antes de acceder a Docker. No se solicitó ni
introdujo contraseña; imagen y preflight **NO EJECUTADOS** por bloqueo de
privilegios. Se requiere evidencia ejecutada por el usuario en su terminal.

**Evidencia parcial aportada por el usuario, 2026-11-02 11:17 -05:00:**
Redis `1a271a30edc6` está running/healthy, proyecto
`comercios-rd-isolated-tests`, sin puertos publicados, sin montajes persistentes,
`/data` en tmpfs de 16 MiB y comando con snapshots/AOF deshabilitados.
La red `comercios_rd_isolated_test_backend` es interna y muestra únicamente
los IDs conocidos de Redis y MySQL. El volumen
`comercios_rd_isolated_test_mysql` tiene etiquetas del proyecto aislado y
`isolated_test_mysql_data`.

La plantilla conjunta falló al acceder a `.HostConfig.Tmpfs` para MySQL,
campo opcional que puede estar ausente; no es evidencia de fallo de MySQL.
No se recibió una línea completa verificable de su salud, puertos y montajes:
repetir solo la inspección MySQL sin ese campo antes de build/preflight.
No se deduce su montaje de datos a partir de las etiquetas del volumen.
Todavía no hay build ni preflight baseline F2 ejecutados.

**Inspección MySQL completada por el usuario, 2026-11-02 11:22 -05:00:**
`ef3c89813c5c` está running/healthy, proyecto
`comercios-rd-isolated-tests`, sin puertos publicados y conectado únicamente
a `comercios_rd_isolated_test_backend`. `/var/lib/mysql` usa el volumen
`comercios_rd_isolated_test_mysql` ya identificado; el único bind informado
es `docker/testing/restrict-test-user.sql` de este repositorio, de solo lectura
en `/docker-entrypoint-initdb.d/10-restrict-test-user.sql`. Comando `mysqld`.
Con la evidencia Redis/red/volumen anterior, la topología Docker concuerda
con el entorno aislado esperado. Identidad SQL, grants, motores, versión y
Redis DB 15 todavía requieren preflight baseline; no se infieren del inspect.

Se entrega al usuario la secuencia ya autorizada de build `phpunit`, guardas
de salud y ejecución explícita de `sale-credit-preflight.php` con
`INF05_F2_PREFLIGHT=1` e `INF05_F2_SCHEMA=0`, usando `--no-deps` y sin `--rm`.
No inicia dependencias ni elimina contenedores: el runner nuevo queda detenido
al finalizar. El servicio se llama `phpunit-accounting`, pero el comando
sobrescrito ejecuta exclusivamente el preflight, no PHPUnit ni migraciones.
Build y baseline permanecen pendientes de resultado; la falta de privilegios
del agente no se considera resuelta por el sudo ejecutado en otra terminal.

**Build aportado por el usuario, 2026-11-02 12:45 -05:00:** imagen
`comercios-rd-isolated-tests-phpunit:latest` construida correctamente,
18/18 pasos, 7.6 s. La salida vuelve al prompt sin creación del runner ni
JSON del preflight. No acredita ejecución/PASS del baseline. La cadena `&&`
puede haberse detenido en una guarda de salud; la salida no identifica cuál
ni confirma la causa. Solicitar inspección de solo lectura de ambos IDs con
estado, salud, exit code y error de Docker antes de volver a ejecutar el runner.
No reconstruir otra vez ni iniciar/recrear servicios a ciegas.

**Estado aportado por el usuario, 2026-11-02 12:51 -05:00:** ambos
contenedores conocidos, Redis `1a271a30edc6` y MySQL `ef3c89813c5c`, figuran
`STATUS=exited`, `RUNNING=false`, `HEALTH=unhealthy`, `EXIT=0`, `ERROR=""`.
Las dependencias están detenidas; el preflight no puede continuar así.
El estado explica que las guardas de salud no pasan ahora, pero no identifica
cuándo, quién o por qué se detuvieron, ni demuestra corrupción o pérdida de
datos. La autorización baseline excluía iniciar servicios existentes:
se requiere autorización adicional para arrancar exclusivamente estos dos
IDs sin recreación, y volver a verificar salud antes del preflight.
Redis es efímero: no se presupone que sobrevivan sus datos anteriores.

**Autorización adicional registrada:** el usuario seleccionó “Autorizar
arranque de ambos IDs, salud y preflight baseline”. Se permite arrancar
únicamente los dos contenedores existentes identificados, sin recrearlos,
comprobar salud y ejecutar el baseline ya autorizado. No incluye migraciones,
PHPUnit, eliminación de recursos ni servicios productivos. Ejecución
privilegiada pendiente en la terminal del usuario; todavía no hay evidencia
de arranque ni preflight posterior.

**Baseline F2 aportado por el usuario, 2026-11-02 13:07 -05:00: PASS
en su alcance de solo lectura.** Ambos IDs arrancaron sin recreación; el
inspect inmediato mostró `starting`, y posteriormente las dos guardas
`healthy` permitieron crear el runner
`comercios-rd-isolated-tests-phpunit-accounting-run-ddf09628d209`.
Guard PASS; MySQL `ef3c89813c5c`, UUID
`bfd92f30-d1c5-11f1-beb5-b24b172266ee`, versión 8.4.13, esquema
`comercios_rd_storage_test`, cuenta `comercios_storage_test@%`, conexión 12.
Grants USAGE global y permisos limitados al esquema aislado, sin GRANT OPTION.
Las 21 tablas requeridas son InnoDB, incluidas customers/receivables/pagos.
Redis `test-redis`, DB 15, ping verdadero, nuevo run_id
`0fb50ba0718d797077240d8ef364ce3c6e631328`; no se presupone conservación de
la sesión Redis anterior.

Resultado explícito `schema_contract=NOT_VALIDATED_BASELINE_ONLY`;
`sales.credit_version`, `receivables.sale_id` y
`sale_checkout_requests.request_key` figuran ausentes. Es lo esperado antes
de aplicar el esquema preparado, no una validación de F2 ni evidencia de que
se hayan comprobado ausentes todos los objetos de una posible DDL parcial.
`phpunit_executed=false`, `migrations_executed=false`.
No se eliminó el runner ni se autoriza su limpieza implícitamente.

**Decisión posterior:** el usuario seleccionó “Autorizar únicamente revisión
de metadatos; sin migración ni PHPUnit”. Se limita la siguiente operación a
revalidar el baseline de solo lectura y consultar definiciones de
`businesses`, `sales`, `receivables`, `sale_checkout_requests` (si existe)
y el registro de la migración F2 en `migrations`. No autoriza aplicar DDL,
pruebas, backfill, reconstruir imagen ni eliminar recursos. Se utilizará la
imagen ya construida y un runner nuevo sin `--rm` y con `--no-deps`.
Resultados de metadatos todavía pendientes; ausencia de los tres campos
testigo no sustituye la revisión completa de tipos, índices, FK y CHECK.

**Metadatos aportados por el usuario, 2026-11-02 13:50 -05:00: revisión
completada en el alcance consultado.** Runner
`comercios-rd-isolated-tests-phpunit-accounting-run-aef156071912`;
baseline nuevamente PASS, UUID/cuenta/esquema/versión/grants/motores y
Redis run_id concordantes, conexión MySQL 509. Se recibieron definiciones
completas de businesses/sales/receivables, ausencia de
sale_checkout_requests y `F2_MIGRATION_RECORD=[]`.

Los IDs y business_id observados son CHAR(36), utf8mb4_unicode_ci; tablas
InnoDB. Sales conserva PK(id), sus dos unicidades de numeración y total
DECIMAL(14,2); no presenta las dos columnas credit_version/credit_effects_at
ni el índice sales_business_identity_unique. Receivables conserva los tres
importes DECIMAL(12,2), amount NOT NULL sin default y paid_amount/balance
NOT NULL DEFAULT 0.00; no presenta sale_id/original_amount, UNIQUE de origen,
FK compuesta ni CHECK F2. La ampliación preparada preserva nullabilidad y
defaults; el nuevo índice padre contiene id ya único. No se observa DDL F2
parcial ni incompatibilidad estructural en esas definiciones.
Esto no es una simulación/ejecución DDL, ni descarta conflictos de nombres
de constraints en otras tablas del esquema o riesgos de bloqueo/capacidad.
Las FK existentes CASCADE/SET NULL y sus límites ya documentados permanecen.

El aviso de contenedor huérfano corresponde al runner baseline conservado
sin --rm; no demuestra un fallo del preflight. No usar --remove-orphans ni
prune: no hay autorización para borrar contenedores. El texto pegado del
comando contiene un tramo final aparentemente alterado, pero su salida
incluye el baseline y los cuatro resultados de metadatos/registro esperados;
no se atribuyen operaciones adicionales ni se supone éxito de otra acción.
Migración F2 y PHPUnit siguen NO EJECUTADOS y sin autorización vigente.

**Autorización DDL posterior:** el usuario autorizó exclusivamente la
migración `2026_11_02_120000_add_sale_credit_integrity.php` en
`comercios_rd_storage_test`, seguida del preflight de esquema. Exige
revalidar servidor/base/permisos, ausencia de conexión a `comercios_rd`,
ausencia de DDL parcial y detenerse ante cualquier error sin reintentar.
No autoriza PHPUnit, seeders, otras migraciones, DML histórico, eliminación
de contenedores ni producción. F2 permanece **PENDIENTE DE VALIDACIÓN
FUNCIONAL**.

Se preparó `tests/Fixtures/sale-credit-migrate.php`, ejecutor acotado que se
transmite por stdin a la imagen ya construida: no requiere reconstrucción ni
montajes nuevos. Primero ejecuta el baseline existente; fija UUID/hostname,
esquema/cuenta, conexión por defecto, charset/collation, repositorio existente
y ausencia de conexiones adicionales instanciadas en **ese proceso**.
No inspecciona ni afirma ausencia de conexiones de otros procesos del host.
El guard de grants rechaza permisos sobre la base real; no intenta conectarse
a ella. Rechaza lectura/escritura repartidas y deshabilita reconexión automática
de la conexión usada para DDL. Un advisory lock sin espera impide dos
ejecutores de este protocolo simultáneos; no bloquea cambios externos que no
usen ese protocolo. Se libera al terminar la conexión, sin limpieza de datos.

Revalida ausencia de todas las columnas/tabla/registro F2, nombres de
índices/constraints en el esquema, tipos/collations de identidad y precisión,
nullabilidad/defaults originales de CxC. Compara SHA-256 del archivo en imagen
con el archivo revisado:
`c5ab4074a94945e408477a0e43232d25e3f1e6eac9654b7a313e6ee092f8c67f`.
Invoca el Migrator con **una sola ruta de archivo**, sin migrador general,
seeders, carga de schema dump o bucles de reintento; registra la migración
en el repositorio estándar. Es el único DML técnico esperado, aparte del DDL.
Después invoca el preflight existente en un proceso nuevo con
`INF05_F2_SCHEMA=1`; si pasa, imprime SHOW CREATE de las tres tablas afectadas
y registro/hash/estado pendiente. Ante error termina, sin ejecutar fases
posteriores ni prometer rollback de DDL MySQL.

Ejecutor validado únicamente con `php -l` y Pint; modo PHP `/dev/stdin`
comprobado sin bootstrap de Laravel ni conexión SQL. No se ha ejecutado el
ejecutor ni aplicado la migración desde el agente: acceso privilegiado
pendiente en terminal del usuario. Su salida posterior es imprescindible para
informar columnas/índices/restricciones efectivamente creados, no solo previstos.

**Migración y esquema ejecutados por el usuario, 2026-11-02 14:23 -05:00.**
Runner `comercios-rd-isolated-tests-phpunit-accounting-run-cd380940ef84`.
Baseline PASS en conexión MySQL 911; `F2_PRE_DDL_GUARD=PASS`,
`OPEN_CONNECTIONS=mysql:comercios_rd_storage_test` y
`F2_MIGRATION=APPLIED`. Identidad revalidada: host `ef3c89813c5c`,
UUID `bfd92f30-d1c5-11f1-beb5-b24b172266ee`, MySQL 8.4.13,
cuenta `comercios_storage_test@%`, permisos limitados al esquema aislado.
Guard de ausencia de DDL parcial y SHA-256 aprobado para la única ruta
autorizada; registro `migrations.id=71`, `batch=3`, nombre/hash concordantes.
No se observan errores ni evidencia de reintentos en esta ejecución.

Preflight posterior en proceso independiente, conexión MySQL 912:
`guard=PASS`, `schema_contract=PASS`, los tres campos testigo presentes y
**22 tablas InnoDB**. Redis DB 15, ping verdadero, run_id
`0fb50ba0718d797077240d8ef364ce3c6e631328`. Las definiciones completas
aportadas confirman:

| Objeto | Columnas / precisión | Índices y restricciones confirmados |
|---|---|---|
| `sales` modificada | `credit_version SMALLINT UNSIGNED NULL`, `credit_effects_at TIMESTAMP NULL`. | `sales_business_identity_unique UNIQUE(business_id,id)`; PK y unicidades de numeración previas conservadas. |
| `receivables` modificada | `sale_id CHAR(36) NULL`, `original_amount DECIMAL(14,2) NULL`; `amount`, `paid_amount`, `balance` ampliados a DECIMAL(14,2) NOT NULL; defaults de cobrado/saldo 0.00 conservados. | `receivables_business_sale_unique UNIQUE(business_id,sale_id)`; `receivables_sale_business_foreign` a `sales(business_id,id)` con DELETE/UPDATE RESTRICT; `receivables_source_check` exige ambos campos de origen nulos o ambos presentes y original no negativo. Preflight confirmó CHECK aplicado y exigido. |
| `sale_checkout_requests` creada | InnoDB; `id`, `business_id`, `sale_id` CHAR(36), este último nullable; `request_key VARCHAR(100) ASCII/ascii_bin`, `payload_hash CHAR(64) ASCII/ascii_bin`, ambos NOT NULL; timestamps nullable. | PK(id); `sale_checkout_business_key_unique UNIQUE(business_id,request_key)`; índice `(business_id,sale_id)`; `sale_checkout_requests_business_id_foreign` a businesses(id) con DELETE RESTRICT; `sale_checkout_sale_business_foreign` a sales(business_id,id) con DELETE/UPDATE RESTRICT. |

El estado final del ejecutor es `PENDING_FUNCTIONAL_VALIDATION`,
`phpunit_executed=false`, `historical_backfill_executed=false`.
`migrations_executed=false` dentro de cada JSON del preflight describe
**ese proceso de solo lectura**; no contradice `F2_MIGRATION=APPLIED` ni el
registro final del ejecutor. No se debe repetir el ejecutor de migración.
El único cambio de datos técnico previsto es registrar la migración; no hay
backfill ni DML sobre ventas/deudas históricas en el archivo autorizado.
No se inspeccionaron valores de negocio para certificar equivalencia fila a
fila; no extrapolar la verificación estructural a validación funcional.

El aviso de runners huérfanos se mantiene como consecuencia de conservarlos
sin --rm; no se autoriza `--remove-orphans`, prune ni borrar contenedores.
Sin cambios productivos, seeders, otras migraciones ni PHPUnit en esta etapa.

**Secuencia restante, sin autorización vigente de ejecución de pruebas:**
preflight renovado antes de cualquier nuevo lote de escritura; autorización
expresa para PHPUnit dirigido Unit/Feature/Integration y regresiones
F1/INF-02/INF-04, resúmenes/e-CF desactivado, incluidas las copias/colas/procesos
propios de integración y su limpieza estrictamente delimitada; aceptación
real de navegador/Livewire. No sustituir por `migrate:fresh`, seeders, suite
indiscriminada, eliminación de recursos existentes o despliegue.

#### Preparación de validación funcional solicitada a las 18:09 — sin PHPUnit

Solicitud actual: exclusivamente preflight de seguridad y revisión de
cobertura, sin correcciones automáticas. Se revisaron preflight, guard,
Compose testing, PHPUnit XML, los ocho archivos dirigidos y los workers
F1/F2/contables. La inspección renovada intentada con `sudo -n docker inspect`
terminó con `sudo: a password is required`; no se obtuvo topología/salud
actual ni se ejecutó preflight desde el agente. La evidencia SQL/Docker
anterior sigue registrada, pero **el gate actual no está completado**.
El usuario intentó el bloque de inspección, pero los saltos de línea sin
continuación separaron los IDs/nombres de los comandos: Docker recibió
inspect sin argumento y el shell interpretó IDs/nombres como órdenes.
No se obtuvo evidencia nueva de topología; corresponde corregir solo el
formato del comando, sin arrancar/recrear/eliminar recursos.
El bloque corregido aportó evidencia a las 18:16: ambos IDs esperados
`ef3c89813c5c`/`1a271a30edc6` running/healthy, proyecto
`comercios-rd-isolated-tests`, única red `comercios_rd_isolated_test_backend`
INTERNAL=true con solo ambos servicios y ningún puerto publicado.
MySQL monta únicamente el volumen dedicado `comercios_rd_isolated_test_mysql`
y el init SQL testing read-only; Redis no tiene mounts, usa /data tmpfs16m,
sin snapshots ni AOF. El volumen está etiquetado para el proyecto testing;
falta comprobar si otro contenedor también lo referencia.
Imagen actual `sha256:9648d3adb826fbe17469c4950f7051ee7ec55e386ea344cf09e65e718ad6143d`,
creada a las 12:45:03.806193805-05:00. Esta inspección renueva solo topología;
no acredita UUID/grants/registro/schema/DB Redis actuales ni equivalencia del
código de la imagen con el revisado. El preflight SQL/Redis/hash continúa
pendiente, sin autorización de PHPUnit.
El preflight enviado por el usuario a las 18:24 verificó
`VOLUME_EXCLUSIVE=PASS`: únicamente el ID MySQL esperado referencia el
volumen dedicado. El proceso renovó guard/schema PASS, 22 tablas InnoDB,
MySQL 8.4.13 conexión3711, UUID/hostname/cuenta/base esperados y grants
únicamente USAGE global y privilegios sobre `comercios_rd_storage_test`.*.
Redis reportó DB15/ping=true/run_id previamente esperado.
La inspección del nuevo runner confirmó imagen `9648d3...`, proyecto/red
testing esperados, sin puertos ni mounts, /tmp tmpfs64m y EXIT=1.

**Preflight completo FALLIDO, no funcionalidad F2:** el wrapper propuesto por
el agente usó `(string) $redis->client('INFO')`; Laravel
`vendor/laravel/framework/src/Illuminate/Redis/Connections/Connection.php:81-84`
define client() como acceso al objeto nativo, no como ejecución de comando.
Resultado: `Error: Object of class Redis could not be converted to string`.
No se alcanzó el JSON final runtime_preflight PASS con conteo de hashes y
registro de migración; no presentarlo como gate completado. El texto inicial
pegado del programa aparece truncado/corrupto; la salida observada acredita
los checks impresos y el fallo, no permite reconstruir todo el stdin ejecutado.
La corrección propuesta exclusivamente para el wrapper es
`$redis->command('client', ['INFO'])`, cuya ruta despacha al método del cliente
nativo (`Connection.php:117-122`); verificar tipo string y db=15 de la respuesta.
No se modifica aplicación/pruebas ni se oculta el EXIT=1; solicitar autorización
para nuevo preflight read-only corregido, sin reutilizar/borrar el runner
fallido, sin build, migraciones o PHPUnit. El aviso de huérfanos no autoriza
eliminar recursos. F2 permanece pendiente.
El usuario autorizó después **únicamente el preflight corregido read-only**.
Se preparó su wrapper en files del workspace de sesión (fuera del repositorio)
para transmitirlo con `php /dev/stdin < archivo`, evitando el pegado del
heredoc largo. Solo corrige el despacho CLIENT INFO y exige respuesta string
con db=15; mantiene guard, hashes y registro/identidad estrictos.
Nuevo runner previsto `comercios-rd-f2-preflight-20261102-1824`, sin --rm,
--no-deps y --pull never. La autorización no incluye PHPUnit ni migraciones;
la ejecución corregida y su PASS siguen pendientes de salida real.
Ejecución corregida recibida a las 18:29: volumen exclusivo PASS;
guard/schema PASS, 22 tablas InnoDB, MySQL conexión3809 con identidad/grants
esperados; Redis ping=true/DB15 configurada/run_id esperado.
Runner1824 conserva imagen/red/proyecto aislados, sin mounts ni puertos,
/tmp tmpfs64m, pero termina **EXIT=1** con
`RuntimeException: Redis connection is not using DB 15.`
No se imprimió el JSON final y el gate continúa bloqueado.

El mensaje del wrapper agrupa tres causas (`!ping`, respuesta no string,
db distinta/no parseable). No permite concluir que la conexión Redis
seleccionó otra DB ni atribuir causa raíz definitiva sin observar el tipo
y db real de CLIENT INFO. La exigencia de string fue una suposición del
wrapper del agente; PhpRedis puede exponer una respuesta estructurada.
El conector Laravel llama select(database) al establecer la conexión
(`vendor/laravel/framework/src/Illuminate/Redis/Connectors/PhpRedisConnector.php:115-116`),
pero esto no sustituye evidencia de la sesión Redis actual.
Propuesta: diagnóstico separado read-only que muestre versión PhpRedis,
tipo de CLIENT INFO, únicamente campo db, tipo/db de rawCommand CLIENT INFO
y getDBNum del cliente cuando esté disponible; sin imprimir datos/keys,
credenciales, direcciones o CLIENT LIST, sin SELECT manual, escritura Redis
o correcciones automáticas. Solicitar autorización limitada para ese
diagnóstico, no repetir ciegamente todo el preflight ni rebajar el guard.
El usuario autorizó **únicamente el diagnóstico Redis read-only**.
Se preparó `f2-redis-diagnostic.php` fuera del repositorio, en files del
workspace de sesión, transmitido por stdin a un runner nuevo previsto
`comercios-rd-f2-redis-diagnostic-20261102-1829`. Primero ejecuta el preflight
existente read-only para guard/schema/endpoints y compara identidad MySQL;
después consulta metadatos de su conexión Redis, no datos ni colas.
El resultado se etiqueta NOT_FULL_PREFLIGHT: un diagnóstico exitoso no
autoriza PHPUnit ni equivale al PASS del wrapper completo.
No se cambió el wrapper fallido ni se corrigió aplicación/pruebas.
Diagnóstico autorizado recibido a las 18:33: PhpRedis **6.2.0**,
CLIENT INFO mediante command devuelve **array** con campo db=15;
rawCommand CLIENT INFO devuelve **string** con db=15; getDBNum disponible
y devuelve15; ping=true. Guard/schema PASS, MySQL conexión3860 con
identidad/grants esperados y Redis run_id conservado. Volumen exclusivo
PASS. Runner diagnóstico usa imagen `9648d3...`, red testing, sin mounts
ni puertos y **EXIT=0**.

**Causa raíz confirmada de ambos fallos del wrapper, no de F2:**
primero se confundió client() de Laravel (getter del objeto nativo) con
CLIENT INFO; después se exigió string para una respuesta array de PhpRedis.
La DB real es15 por tres comprobaciones de la misma conexión, no otra DB.
La corrección propuesta ahora está sustentada por resultado real:
reemplazar únicamente la llamada del wrapper por
`$redis->command('rawCommand', ['CLIENT', 'INFO'])`, manteniendo tipo string,
regex de db=15 y rechazo inmediato ante respuesta/DB inesperadas.
Solicitar autorización de esa corrección operativa y nuevo preflight completo
read-only (incluidos 36 hashes y registro F2 id71/batch3), no PHPUnit.
El diagnóstico exitoso no cerró ese gate ni produjo resultados funcionales.
El usuario autorizó después la corrección operativa y otro preflight completo
read-only. Se cambió solo la llamada CLIENT INFO del wrapper de sesión a
rawCommand; no se modificaron aplicación ni pruebas. Se preparó un lanzador
de sesión `run-f2-functional-preflight.sh` para renovar salud/topología
Docker/volumen exclusivo/imagen y transmitir los 36 hashes y el wrapper por
stdin a `comercios-rd-f2-preflight-20261102-1833`. No inicia dependencias,
reconstruye imágenes o elimina recursos; captura el fallo del runner y
lo inspecciona sin transformar EXIT=1 en éxito. Ejecutar este lanzador es
solo preflight, no autorización de PHPUnit. Falta su resultado real.
**Actualización 18:42 — preflight completo confirmado por el usuario:**
el usuario informa PASS, 36 archivos de imagen verificados,
`comercios_rd_storage_test`, F2 registrada id71/batch3, Redis DB15 y runner
EXIT=0, sin PHPUnit, nuevas migraciones o seeders. Esta actualización se
atribuye a su confirmación explícita; no se recibió en este turno el stdout
completo del lanzador. Se conserva la evidencia técnica previa y los
dos fallos del wrapper, sin atribuirlos a la funcionalidad F2.
Se revisaron nuevamente selección, Tests\TestCase/TestingDatabaseGuard,
Compose, PHPUnit XML y escenarios de crédito/integración.

La selección sigue siendo los ocho archivos de la tabla siguiente. PHPUnit
usará únicamente el servicio phpunit-accounting de la imagen verificada,
--no-deps/--pull never, sin --rm, con rutas explícitas y reporte JUnit
`storage/logs/inf05-f2-functional.junit.xml` dentro del nuevo runner
`comercios-rd-f2-functional-20261102-1842`. El comando preparado comprueba
antes la imagen y la exclusividad del volumen; cualquier discrepancia detiene
la ejecución. Tests\TestCase comprueba esquema/grants antes de cada aplicación;
los workers además exigen endpoints/prefijos propios. No hay permisos de
datos sobre comercios_rd ni otros esquemas de negocio en los grants verificados;
las consultas a information_schema son metadatos, no acceso a negocio real.
No se amplía esta afirmación a procesos ajenos del host o credenciales distintas.

**Comando propuesto, todavía sin ejecutar y sujeto a autorización explícita:**

```bash
cd /home/deleon/comercios-rd && \
test "$(sudo docker image inspect --format '{{.Id}}' \
  comercios-rd-isolated-tests-phpunit:latest)" = \
  sha256:9648d3adb826fbe17469c4950f7051ee7ec55e386ea344cf09e65e718ad6143d && \
test "$(sudo docker ps -aq --no-trunc \
  --filter volume=comercios_rd_isolated_test_mysql)" = \
  ef3c89813c5c598bc5a0d301ccedaa795a2048cfdc0990fefacd65f9747f3082 && \
sudo docker compose --profile testing --profile accounting-testing \
  --env-file .env.testing.docker -f compose.testing.yaml \
  run -T --no-deps --pull never \
  --name comercios-rd-f2-functional-20261102-1842 \
  phpunit-accounting ./vendor/bin/phpunit \
  --colors=never --testdox \
  --log-junit storage/logs/inf05-f2-functional.junit.xml \
  tests/Unit/SaleCreditContractTest.php \
  tests/Unit/SaleFinancialIntegrityTest.php \
  tests/Feature/SaleCreditIntegrityTest.php \
  tests/Feature/FinancialOperationsTest.php \
  tests/Feature/PosApiTest.php \
  tests/Feature/ScaleDailySummaryTest.php \
  tests/Feature/EcfDisabledTest.php \
  tests/Integration/PostSaleAccountingQueueTest.php
```

La aprobación de esta selección autorizaría fixtures/DML transaccional y
DDL exclusivamente de copias vacías nuevas de integración, colas DB15 y
procesos propios, con la limpieza acotada descrita abajo; no autoriza
migraciones, seeders, backfill, borrado de recursos existentes ni correcciones
automáticas ante fallos. El lote no prueba anulación de cobros ni todas las
carreras DATA-02 y no puede cerrar esos requisitos por mera ejecución verde.
No hay todavía resultados funcionales/conteos/aserciones que registrar.
**Autorización explícita recibida en la revisión de las 18:42:**
«Autorizar únicamente estos ocho archivos en Docker aislado; DATA-02 sigue
pendiente». Autoriza el comando y los efectos delimitados anteriores, no
otros archivos, migraciones, seeders, código de corrección ni cierre DATA-02.
Se preparó una copia ejecutable del comando exacto en
`run-f2-functional-tests.sh` del workspace de sesión, fuera del repositorio,
para evitar errores de pegado; no ejecuta otras pruebas ni limpia recursos.
La ejecución requiere el sudo interactivo del usuario, no disponible para
el agente según las comprobaciones previas. **Todavía no se recibió salida
de PHPUnit ni se ejecutó desde el agente**; no declarar resultados antes
de obtener salida real del runner y revisar el JUnit.
No se ejecutaron PHPUnit, migraciones, seeders, build o escrituras de negocio
en esta revisión. No se modificaron código ni pruebas para acomodar resultados.

**Selección propuesta, pendiente de autorización de ejecución:**

| Nivel | Archivos completos seleccionados |
|---|---|
| Unit | `tests/Unit/SaleCreditContractTest.php`; `tests/Unit/SaleFinancialIntegrityTest.php`. |
| Feature | `tests/Feature/SaleCreditIntegrityTest.php`; `tests/Feature/FinancialOperationsTest.php`; `tests/Feature/PosApiTest.php`; `tests/Feature/ScaleDailySummaryTest.php`; `tests/Feature/EcfDisabledTest.php`. |
| Integration | `tests/Integration/PostSaleAccountingQueueTest.php` (ruta explícita: Integration no está incluida en la selección por defecto del XML). |

**Cobertura estática, no resultados de pruebas:**

| Requisitos solicitados | Evidencia preparada y límites |
|---|---|
| 1–4: crédito válido, módulo y cliente/empresa | SaleCreditIntegrityTest contiene crédito válido, módulo deshabilitado y cliente ausente/inexistente/ajeno/inactivo/borrado; separación de claves por empresa, membresía/permisos/autor. PosApiTest cubre replay y módulo deshabilitado. |
| 5–6 y 10,15: rollback, unicidad y venta/CxC/outbox | Feature compara snapshots/stock ante save false de cabecera/línea/CxC/outbox, FK 1452, fallo tardío del resumen e inline, y rollback exterior. Integration fuerza FK real en copias propias y duplicado SQL 1062 tras bloqueo independiente. |
| 7–8: idempotencia y respuesta perdida | Hash canónico Unit; Feature replay sin efectos y conflicto de clave tras cambios de catálogo; Integration mata el proceso antes del commit y después del commit antes de responder, reintenta el mismo UUID y comprueba una venta/deuda/efectos/asiento. No equivale a aceptación real de sessionStorage/navegador ni todas las conversiones. |
| 9: concurrencia MySQL real | Tres modos F2 (misma solicitud, payload incompatible, INSERT SQL duplicado de CxC), IDs de conexión distintos, sincronización stdin, timeout 1205 bajo bloqueo y replay/conflicto/1062 tras commit. Son escenarios preparados, aún no evidencia ejecutada. |
| 11–12: asientos y compatibilidad | Inline/rollback, outbox y entrega duplicada, Redis inaccesible simulado y recuperación con worker real, débito 1201 y asiento/evento únicos. La integración conserva ocho competidores F1 y regresiones INF-02/INF-04. FinancialOperations, ScaleDailySummary y EcfDisabled completan regresiones previas; las 97/999 históricas no validan el código actual. |
| 13: cobros concurrentes y sobrepago | **SIN COBERTURA CONCURRENTE en la selección.** FinancialOperationsTest:459 comprueba pagos/importes inválidos secuencialmente sobre deuda manual; SaleCreditIntegrityTest comprueba cobro vinculado secuencial. Receivable::registerPayment sigue validando saldo del modelo antes de insertar, fuera de una unidad pago/saldo/caja completa. No presentar estas pruebas como cierre DATA-02. |
| 14: anulación de cobros y devoluciones | Devolución vinculada parcial/total y conservación de principal original/pagos/cancelación de venta están preparadas. **Anulación de cobro y carreras cobro/devolución/anulación no tienen prueba dirigida** en estos ocho archivos. El callback deleted de ReceivablePayment no es evidencia de atomicidad o concurrencia. DATA-02 completo requeriría un lote aparte; no se amplía el alcance de F2 automáticamente. |

**Escrituras futuras que requieren aprobación:** Unit de contrato no escribe
datos; Unit F1 inicializa Laravel y comprueba el guard. Feature utiliza
DatabaseTransactions (salvo EcfDisabled, que prueba bloqueos/mocks): crea
usuarios/negocios/productos/clientes/ventas/líneas/CxC/pagos/outbox/asientos,
movimientos, lotes/caja/resúmenes propios; actualiza y elimina registros
sintéticos, incluido un borrado SQL de CxC para demostrar rechazo del
consumidor, y revierte las transacciones. No hace backfill de negocio.
Las fábricas usadas no son seeders.

Integration crea **22 tablas vacías nuevas por escenario**, con prefijo
`crd_accounting_test_<16hex>_`, mediante CREATE TABLE LIKE; añade dos FK
entre las copias, ejecuta commits/rollback reales, inserciones/actualizaciones,
colas Redis DB15 con prefijos/nombres propios, trabajos fallidos y procesos
PHP propios, incluidos SIGKILL y reintentos deliberados del escenario.
Al finalizar detiene solo sus procesos y elimina solo sus tablas/colas
creadas. No usa FLUSHDB/FLUSHALL, prune, --remove-orphans ni borra contenedores
o volúmenes existentes. CREATE/ALTER/DROP de copias vacías **sí es DDL de
prueba**, pero no aplica migraciones ni altera las tablas base.

Ninguno de los archivos/worker seleccionados invoca migrador, seeders,
RefreshDatabase o DatabaseMigrations; el esquema base F2 debe seguir aplicado.
No se necesita otra migración. Los workers reciben explícitamente endpoints,
prefijo propio y colas; Compose del runner usa --no-deps sin montajes de
datos productivos. Antes de ejecutar hay que renovar topología y comprobar
en el runner UUID/hostname, esquema/cuenta/grants, registro único de F2
id71/batch3, contrato de esquema, DB15 y ausencia de otra conexión instanciada;
comparar hashes de archivos seleccionados/workers con los revisados localmente.
No afirmar ausencia de conexiones productivas de otros procesos del host.

**Primera ejecución funcional autorizada — salida recibida 18:55:**

Fuente: terminal pegado en `paste-1793663703571.txt` del workspace de sesión,
líneas 1–2053. Se ejecutó el lanzador aprobado y se creó
`comercios-rd-f2-functional-20261102-1842`, sin --rm. PHPUnit **11.5.57**,
PHP **8.3.36**, `/var/www/html/phpunit.xml`, tiempo **04:26.442**,
memoria **84.50 MB**, progreso **175/175**, resumen
`ERRORS! Tests: 175, Assertions: 1300, Errors: 45.`
Conteo independiente de marcas TestDox: **130 aprobadas, 45 con error**.
Sin fallos de aserción, omitidas o risky reportados; no extrapolar ausencia
de omisiones a los requisitos sin prueba. Falta recoger JUnit y EXIT real
del runner para contrastar el reporte almacenado; no inventar código EXIT.

| Archivo/clase | Aprobadas | Errores |
|---|---:|---:|
| SaleCreditContractTest | 9 | 0 |
| SaleFinancialIntegrityTest | 11 | 0 |
| SaleCreditIntegrityTest | 0 | 42 |
| FinancialOperationsTest | 41 | 1 |
| PosApiTest | 20 | 0 |
| ScaleDailySummaryTest | 5 | 0 |
| EcfDisabledTest | 5 | 0 |
| PostSaleAccountingQueueTest | 39 | 2 |
| Total | 130 | 45 |

**Causas verificadas y propuesta antes de cualquier corrección:**

1. **42 errores de preparación, no 42 demostraciones de defecto financiero.**
   MySQL 1364: businesses.slug no tiene default. El fixture
   `SaleCreditIntegrityTest.php:474-476` usa Business::withoutEvents y omite
   slug; por ello no se ejecuta el hook saving que lo genera
   (`Business.php:727-735`). Todas las pruebas de ese archivo abortan
   construyendo contexto antes de probar el comportamiento F2.
   Propuesta: slug único explícito solo en el fixture, conservando
   withoutEvents; no hacer nullable/default la columna ni cambiar el modelo.

2. **Una regresión real de devolución vinculada.**
   `FinancialOperationsTest.php:429` llega a
   `SaleCreditService.php:180`: `$locked->payments()->withoutBusinessScope()`.
   payments() retorna HasMany; withoutBusinessScope es un helper estático
   del modelo (`BelongsToBusiness.php:252-254`), no un scope local que el
   builder de la relación pueda resolver. BadMethodCallException.
   Propuesta: usar el scope local existente forBusiness con la empresa de
   la CxC (`BelongsToBusiness.php:257-261`), conservando relación por
   receivable_id, filtro de empresa, orden por id y FOR UPDATE. No suprimir
   errores ni ampliar acceso entre empresas. El mismo recálculo se usa
   para cobros vinculados; sus pruebas Feature aún están bloqueadas por
   el fixture. No afirmar rollback completo de esta devolución fallida
   sin las aserciones de efectos que el error impidió ejecutar.

3. **Dos timeouts de integración, causa raíz todavía no probada.**
   Modos same/conflict (datasets #0/#1) alcanzan READY y la comprobación
   de conexiones distintas, pero awaitMarker(BLOCKED) en
   `PostSaleAccountingQueueTest.php:189` agota el timeout de proceso30s
   (`startCreditWorker`:944-955; awaitMarker:1059-1066).
   El competidor SQL unique-source sí completa el escenario.
   La salida no contiene la última operación SQL ni buffers del worker
   al lanzar ProcessTimedOutException; no permite concluir si es espera
   SQL, bloqueo de otro recurso o sincronización del harness.
   Propuesta: diagnóstico acotado con stdout/stderr/etapas del worker
   preservados al timeout y causa SQL, manteniendo conexiones independientes,
   barreras y comprobación 1205. No aumentar timeout ni sustituir el caso
   por ejecución secuencial para conseguir verde.

**Evidencia positiva real, limitada al lote ejecutado:**
competidor CxC SQL con bloqueo1205/rechazo1062; ocho competidores F1;
contención contable/leases/numeración en workers independientes. Ambos
SIGKILL F2 antes/después del commit pasan, incluyendo recuperación con la
misma solicitud después de respuesta perdida y un único asiento/CxC.
Rollback de FK1452 en copias propias comprueba cero venta/líneas/CxC/
movimientos/resumen/outbox/request/asiento, stock original y cola vacía.
Pasan recuperación tras Redis inaccesible simulado, reentrega contable,
rollback contable/outbox, replay POS sin duplicar deuda y contratos Unit.
Estas aserciones ejecutadas no validan los dos checkouts competidores
fallidos ni los 42 escenarios Feature abortados. No se imprimieron los IDs
de conexiones de los casos exitosos; se verificaron mediante assertNotSame
en las pruebas, no se inventan valores.

**Regresiones/riesgos:** devolución a crédito rota, prueba central F2 no
ejercitada y concurrencia same/conflict incompleta. DATA-02 (anulación
de cobros/sobrepago/carreras y atomicidad completa) sigue fuera de cierre.
Los resultados positivos F1/INF-02/INF-04 son del alcance seleccionado,
no una certificación exhaustiva de esas áreas o producción.
No se recibieron evidencias de daños en históricos/producción; tampoco
se inspeccionaron para afirmar equivalencia fila a fila. La ejecución no
invoca migraciones/seeders/backfill; no se borraron recursos existentes
desde el agente y el aviso de huérfanos no autoriza limpieza.
En este análisis solo se actualizó el informe: no aplicación/pruebas,
imagen, esquema, credenciales o nueva ejecución de PHPUnit.

**Bloqueos de cierre:** preflight y autorización inicial recibidos; la
ejecución completa FALLÓ. Falta corregir causas verificadas tras aprobación,
diagnosticar los timeouts y autorizar/ejecutar nueva validación con imagen
revisada y preflight renovado. No repetir el runner fallido ni usar un build
nuevo sin autorización específica.
El usuario autorizó después **únicamente fixture slug y scope del recálculo,
sin build ni PHPUnit**. Se añadió slug único explícito en el fixture
withoutEvents y se reemplazó el helper estático inexistente de HasMany por
forBusiness(empresa de la CxC). El scope existente conserva el filtro
calificado por empresa; la relación sigue filtrando receivable_id y se
mantienen orden por id/FOR UPDATE. No se modificaron el esquema, la lógica
de registro de pagos DATA-02 o los dos casos concurrentes con timeout.
Los dos archivos cambiados ya no coinciden con la imagen previamente
verificada: no ejecutar el lanzador de PHPUnit antiguo ni construir imagen
automáticamente. Falta aprobación de build/preflight/ejecución posterior y
diagnóstico separado de los timeouts. Estos cambios no convierten el lote
fallido 175/1300/45 en validación positiva.
Validación permitida de estas dos correcciones: PHP lint de ambos archivos
PASS y `vendor/bin/pint --test tests/Feature/SaleCreditIntegrityTest.php
app/Services/SaleCreditService.php` PASS. No se ejecutó PHPUnit, build,
migraciones o diagnóstico concurrente nuevo. Resultado funcional posterior
a la corrección: **NO EJECUTADO**.
Los requisitos 13 y la parte concurrente/anulación del 14 no están cubiertos:
una ejecución exitosa de la selección no permite afirmar satisfechos los
quince requisitos ni cerrar DATA-02. No se añadieron pruebas/correcciones
durante esta revisión ni se estimaron conteos/aprobados/aserciones como
resultados. Estado: **F2 PENDIENTE DE VALIDACIÓN FUNCIONAL; SaaS NO APTO
PARA PRODUCCIÓN**.

**Aceptación y riesgos residuales.** Siguen vigentes los criterios anteriores:
exactamente una CxC por crédito confirmado, cero efectos tras fallo, replay sin
duplicados y evidencia desde conexiones independientes, DDL verificado y
regresiones aprobadas. SQL/PDO directo, builders reemplazados y acciones FK
pueden eludir las barreras PHP: la FK hijo-padre y el UNIQUE no garantizan por
sí solos que todo padre tenga hijo, ni impiden borrar deuda por SQL o alterar
cliente mediante cascadas/SET NULL. No se afirma integridad universal de DB.
También quedan pendientes legado, DATA-02, F6, aceptación completa de UI,
supervisión/recuperación física y hallazgos de despliegue. **F2 SIN VALIDAR;
INF-05 incompleto; SaaS NO APTO PARA PRODUCCIÓN.**

### Diseño pendiente: pagos idempotentes y snapshot financiero

Para cuentas por cobrar/pagar, introducir un servicio transaccional compartido
sin duplicar observadores: recuperar el documento mediante `business_id` y
`lockForUpdate`, recalcular saldo desde pagos persistidos dentro de la misma
transacción, rechazar exceso y registrar pago, saldo, caja/asiento juntos.
Mantener un UUID de solicitud estable durante el reintento de UI/API, con
unicidad por empresa/origen/solicitud y hash del payload: devolver el resultado
original para el mismo payload y rechazar reutilización con importe/moneda
distintos. Un UUID nuevo por reintento no resuelve duplicados. Coordinar los
observadores actuales para evitar doble recálculo/asiento/caja. Diseñar pruebas
con dos conexiones independientes y fallos entre cada efecto. No reparar
automáticamente pagos históricos ni deduplicar por coincidencia de importes.

Para ventas nuevas, proponer una tabla aditiva de snapshots por línea:
identificador, versión, `business_id`, venta/línea, moneda, cantidad,
costo unitario/total decimal, origen del costo y momento de captura.
La fuente debe ser la valoración realmente utilizada al confirmar/inventariar,
no una lectura posterior del catálogo. Mantener la precisión financiera
acordada y congelar importes/impuestos/descuentos que requiera el contrato.
Una restricción única por empresa/línea/version evita copias; el vínculo se
valida contra venta y línea de la misma empresa.

Guardar snapshot, confirmación y evento outbox en la transacción MySQL que ya
envuelve la venta: rollback de cualquiera revierte los tres. Impedir
actualización/eliminación de snapshots y de campos financieros confirmados por
los caminos soportados, con permisos de DB apropiados; devoluciones/correcciones
crean nuevos documentos/eventos, no reescriben la evidencia. Una protección
Eloquent sola no cubre SQL directo.

El consumidor usaría exclusivamente el snapshot para documentos nuevos
versionados, conservando idempotencia del evento y commit atómico
asiento/outbox de INF-04. Costo conocido `0.00` es válido; ausencia tiene un
estado explícito y no se convierte en costo actual. Para legado sin snapshot,
inventariar casos y definir tratamiento autorizado/manual antes de modificar
datos; no backfill automático, no asumir tablas vacías ni cambiar silenciosamente
la contabilidad histórica. Este diseño no fue implementado y requiere aprobación
de esquema, precisión y comportamiento.

### Orden de cierre y aceptación

Las entradas siguientes conservan la cronología y sus estados provisionales;
el cierre vigente del lote TLS figura al final y en el encabezado INF-06.

**Autorización de preparación recibida:** el usuario seleccionó
`authorize-tls-preparation`: reconstrucción de imagen, certificados y dos
MySQL tmpfs exclusivos, con preflight de lectura. No autoriza PHPUnit,
migraciones ni cambios sobre los recursos existentes. Ejecución privilegiada
y evidencias aún pendientes; INF-01 sigue CORREGIDO SIN VALIDAR.

**Incidente de preparación aportado por el usuario:** el listado inicial no
mostró contenedores TLS. El build falló por dependencia `test-redis` fuera del
perfil activo; el generador `0f3ba2e66de1` usó la imagen antigua y no encontró
el fixture. Se creó `comercios_rd_isolated_test_tls_certs`, sin emisión de
certificados acreditada. A pesar del fallo, se iniciaron los MySQL TLS
`9714785e834fc9103ccf0531c6ceaf7e03e9fee80c97379dc559ee6a479c05f1`
y negativo
`56f4d9543e32162e7b5e1f954dea1e6db6f3294c3e5957fbb3e77fac4cd9f9a4`,
ambos con imagen
`sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69`.
La inspección acredita proyecto de pruebas, misma red interna conocida,
sin puertos y `/var/lib/mysql` tmpfs 1 GiB; no acredita UUID/grants/cipher.
Healthy procedía de `mysqladmin ping`, no de autenticación TLS. No se aportó
preflight ni PHPUnit.

Se corrigió la instrucción de build para habilitar ambos perfiles sin iniciar
servicios y se exige encadenar pasos con `&&`. El servidor TLS ahora tiene
guard de archivos antes de arrancar y healthcheck autenticado con
`VERIFY_IDENTITY`/cipher; cambios locales, no aplicados todavía al contenedor
en ejecución. No se modificó el MySQL persistente, Redis ni volumen alguno.
Reaplicar el cambio al contenedor TLS necesita revisar el efecto sobre tmpfs.
La validación sigue bloqueada.

**Build/generación recuperados — 2026-10-31:** el usuario ejecutó build con
`testing` y `accounting-testing`, encadenado con `&&`. Imagen publicada:
manifest list
`sha256:0bf2afe4e1affee05f71f76bba9b668e1f7665376e9046fb8740a468fa3d040d`,
config
`sha256:68ceaeac85e6c0a0ea222ff03d269c0c883214c5a61ad6cd05a0ee3adce0c182`.
El generador `407d92b9d15a` terminó `TLS_TEST_CERTIFICATES_CREATED`.
Se acredita construcción y emisión, no negociación TLS ni actualización del
contenedor MySQL ya iniciado. Antes de autorizar su recreación, comprobar
por lectura identidad, grants y ausencia de tablas de aplicación en el
esquema tmpfs. No hay preflight TLS ni pruebas aprobadas todavía.

**Lectura previa a recreación TLS — 2026-10-31:** el usuario consultó por
socket local el contenedor exclusivo `9714785e834f`. Esquema
`comercios_rd_storage_test`, UUID `2527cdab-d4ee-11f1-b681-32cf268b1d42`,
cuenta `comercios_storage_test@%`, `require_secure_transport=1` y
**cero tablas de aplicación** visibles en ese esquema. Grants: USAGE global
y únicamente SELECT/INSERT/UPDATE/DELETE/CREATE/DROP/REFERENCES/INDEX/ALTER
sobre el esquema de pruebas, sin GRANT OPTION. Confirma el alcance y la
ausencia de tablas que conservar allí; no acredita cifrado por ser socket.
La recreación del contenedor descartaría su tmpfs y regeneraría identidad/
esquema/usuario. Pendiente autorización específica; no tocar los otros
contenedores ni volúmenes. PHPUnit sigue sin autorización.

**Autorización específica recibida:** el usuario seleccionó
`authorize-tls-recreation`, únicamente para recrear `test-mysql-tls`,
reinicializar su tmpfs y ejecutar inspección/preflight de lectura.
No autoriza recrear el servidor negativo ni los recursos persistentes,
eliminar volúmenes o ejecutar PHPUnit. Nueva identidad y TLS pendientes
de evidencias aportadas por el usuario.

**Recreación aplicada, TLS bloqueado:** `up --wait` agotó 180 s y detuvo
inspect/preflight por `&&`. Diagnóstico posterior: nuevo contenedor
`1030fc19f717a89593c039bd2706791b9ed39f26403fcd7ae7a2671737abc0e7`,
running/unhealthy. MySQL 8.4.13 inicializó esquema/usuario y grants, pero
registró `server.pem: certificate signature failure`; el healthcheck
rechaza con ERROR 2026 / `certificate verify failed`. El control fail-closed
está detectando el problema, no una validación positiva. Pendiente inspección
criptográfica de los artefactos para distinguir algoritmo, firma/CA y
correspondencia de clave; no asumir aún la causa exacta. No se desactiva
verificación ni se regeneran artefactos existentes sin autorización.

**Causa de emisión identificada por lectura:** diagnóstico `d87b68278d49`:
CA SHA-256 fingerprint
`d936b344b37acb69bc82fdd208c5e5a34751113847986441540763d944eaf176`,
certificado
`038ffeefa498f85c30b86c2c66c12d69b793d99aafe9d3b5acc785acdc47e904`.
Ambos estaban firmados con **RSA-SHA1**. Firmas matemáticas=1, clave
coincidente, SAN `DNS:test-mysql-tls` y vigencia 2026-10-31/2026-11-07;
no era una clave discordante ni hostname incorrecto. PHP aceptó la
comprobación de propósito/cadena, pero MySQL rechazó el certificado.
El generador omitía `digest_alg`.

Se corrigió el fixture para exigir SHA-256 en CSR/firma y comprobar algoritmos,
firmas y clave antes de publicar. Nuevo volumen de pruebas previsto:
`comercios_rd_isolated_test_tls_certs_sha256`; el anterior permanece intacto,
incluido su archivo privado de pruebas. No se cambian contraseñas o archivos
de credenciales existentes, ni se rebaja TLS. Nueva emisión aún no ejecutada:
necesita autorización específica y comprobación criptográfica posterior.
La recreación adicional del TLS actual requiere renovar la lectura del
tmpfs y autorización; no se asume su UUID ni vacío por evidencia anterior.

**Nueva emisión autorizada:** el usuario seleccionó
`authorize-sha256-generation`: build, generación en el volumen SHA-256
dedicado y lecturas criptográficas/identidad del TLS actual. El volumen SHA-1
y los contenedores se conservan; no incluye recreación adicional ni PHPUnit.
Pendiente ejecución y resultados verificables.

**Emisión SHA-256 comprobada — 2026-10-31:** build publicado con manifest list
`sha256:017b3e3ae70e20e01ce480df227795ef8c3478716b4cdee9af9b267ee2dcfb0c`
y config
`sha256:4e11c67ff326be0e12f849735e7126ab1dea66056b374422966bc22cc5112f2d`.
Nuevo volumen `comercios_rd_isolated_test_tls_certs_sha256` creado;
generador `4f87c60734be` terminó correctamente. Diagnóstico `8206c5e39b3c`:
CA y servidor RSA-SHA256, ambas firmas=1, clave y propósito/cadena válidos,
SAN correcto, sin errores OpenSSL; vigencia 2026-10-31/2026-11-07.
Fingerprint CA
`e82e02cbc2e78e8e26ab639017f8d9e2162641f629b70021633322593028dbf0`
y servidor
`48e7db31aa1b683be0146ac9476ef85a39594b5c1398e0716ca68d3f65abe59e`.
La advertencia Compose sobre la etiqueta del volumen anterior confirma que
se dejó intacto; no seguir su sugerencia de eliminarlo.

Lectura renovada por socket del TLS `1030fc19f717`: UUID
`476c75d4-d535-11f1-ac84-22ac6e5b5d5e`, esquema/cuenta de pruebas,
cero tablas de aplicación y mismos grants exclusivos esperados. No acredita
TLS, porque el contenedor sigue montando el volumen SHA-1 anterior. Pendiente
autorización de recreación exclusiva para montar SHA-256 y renovar el
preflight de ambos servidores. Sin PHPUnit ejecutado.

**Aplicación SHA-256 autorizada:** el usuario seleccionó
`authorize-sha256-tls-recreation`: recrear únicamente el TLS `1030fc19f717`
para montar el volumen SHA-256 y ejecutar inspección/preflight de lectura.
No autoriza otros contenedores, eliminación de volúmenes ni PHPUnit.
Nueva identidad y negociación real todavía pendientes.

**Preflight TLS positivo — 2026-10-31:** el usuario recreó únicamente
`test-mysql-tls`. Contenedor
`c36aa03c2cb8e6cbbfb19e518c2f94a77cb71eda028465cb080c9bddb20ae209`,
imagen MySQL
`sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69`,
healthy con check autenticado. Inspección: proyecto aislado, red interna
conocida, sin puertos, tmpfs 1 GiB, scripts en lectura y volumen SHA-256
montado ro. Preflight `eb7200c8098a`: **guard PASS**, MySQL 8.4.13,
UUID `a7fc925d-d537-11f1-b477-4e3466c53ed3`, esquema/cuenta de pruebas,
cipher `TLS_AES_256_GCM_SHA384` y fingerprint CA
`e82e02cbc2e78e8e26ab639017f8d9e2162641f629b70021633322593028dbf0`.
Se acredita negociación positiva en ese entorno, no producción.

Servidor negativo sin recrear: `56f4d9543e32`, UUID
`25344cd0-d4ee-11f1-8e7e-221f058f8bef`, mismo esquema/cuenta dedicada,
**no_tls_guard PASS**, cipher vacío y grants exclusivos esperados.
La advertencia Compose dejó intacto el volumen SHA-1; no se elimina.
Pendientes pruebas adversas y regresiones de portabilidad mediante archivos
seleccionados, con autorización separada. No existe todavía resultado
PHPUnit INF-06 ni aprobación productiva.

**Pruebas dirigidas autorizadas:** el usuario seleccionó
`authorize-targeted-tls-tests`: exclusivamente `MysqlTlsPolicyTest`,
`MysqlTlsTest`, `PortabilityWorkflowTest` y `PortabilityRestoreOperationsTest`
en el runner TLS, y check Oracle de lectura en el contenedor TLS verificado.
No incluye suite general, Feature/Financial ni restauraciones root.
Temporales propios y consultas/dump de estructura del esquema aislado;
sin migraciones, seeders, importaciones o cambios de servidores/volúmenes.
Resultado PHPUnit/Oracle todavía pendiente.

**Cierre verificado del lote TLS:** runner `ea6efdcd57d9`, imagen previamente
identificada por config
`sha256:4e11c67ff326be0e12f849735e7126ab1dea66056b374422966bc22cc5112f2d`.
PHP 8.3.36, PHPUnit 11.5.57, **OK (72 tests, 281 assertions)**,
4.804 s y 64.50 MB. Cuatro archivos seleccionados:
`tests/Unit/MysqlTlsPolicyTest.php`, `tests/Integration/MysqlTlsTest.php`,
`tests/Unit/PortabilityWorkflowTest.php` y
`tests/Unit/PortabilityRestoreOperationsTest.php`.
El check Oracle en `c36aa03c2cb8` aprobó conexión autenticada, CA incorrecta,
hostname incorrecto y servidor sin TLS. No hubo cambios funcionales después
de esta ejecución; las actualizaciones finales son documentales.

Se validaron PDO Laravel/independiente, CNF MariaDB de backup/restore, control
de configuración ambiental, permisos de temporales, rechazo de inyección y
política fail-closed, con regresiones existentes de portabilidad. La emisión
RSA-SHA256 resolvió el fallo de preparación sin desactivar controles.
El preflight previo y las identidades de ambos servidores delimitan el
aislamiento. No se ejecutaron Feature/Financial, restauraciones root ni
pruebas de recuperación integral del SaaS; no ampliar la conclusión a ellas.

**Archivos del lote:** servicios `MysqlTlsPolicy`, `VerifiedMysqlConnector`,
`PdoMysqlServerIdentityReader`, `PortabilityRestoreOperations`;
`AppServiceProvider`; comandos `PortabilityBackup`, `PortabilityRestore`,
`SecurityPreflight`; `config/database.php`; `compose.testing.yaml`;
dos archivos PHPUnit nuevos; fixtures `mysql-tls-certificates.php`,
`mysql-tls-preflight.php`, `mysql-tls-certificate-inspect.php`;
scripts `mysql-tls-client-check.sh`, `mysql-tls-server-entrypoint.sh`;
este informe y `docs/mysql-tls.md`, `docs/deploy.md`, `docs/staging-setup.md`.
No hay migración nueva ni modificación del código INF-02/INF-04.

**Límites residuales:** TLS productivo/DigitalOcean no inspeccionado; driver
Laravel `mariadb` fuera del lote; revisar login paths si el runtime operativo
usa Oracle. Un dump de estructura del esquema vacío no prueba restauración
de datos reales. Los certificados son desechables, vencen el 2026-11-07 y
nunca se despliegan. Ambos volúmenes contienen material de pruebas y archivo
privado 0600; el SHA-1 se preservó sin eliminarlo. Los dos MySQL temporales
quedaron detenidos y verificados por ID en el cierre siguiente. No detener
MySQL persistente, Redis ni borrar volúmenes.

**Parada temporal autorizada:** el usuario seleccionó
`authorize-stop-tls-test-servers`, exclusivamente para detener y verificar
`c36aa03c2cb8` y `56f4d9543e32`. Contenedores/volúmenes conservados;
MySQL persistente y Redis excluidos.

**Cierre temporal confirmado — 2026-10-31:** el usuario ejecutó la parada
autorizada y verificó ambos IDs:
`c36aa03c2cb8e6cbbfb19e518c2f94a77cb71eda028465cb080c9bddb20ae209`
y `56f4d9543e32162e7b5e1f954dea1e6db6f3294c3e5957fbb3e77fac4cd9f9a4`,
**STATUS=exited, RUNNING=false**, proyecto `comercios-rd-isolated-tests`.
Se conservaron contenedores y volúmenes; no hubo comandos de parada sobre
el MySQL persistente o Redis. Los mensajes posteriores `command not found`
mostraron tokens del prompt/IDs usados como comandos; la inspección repetida
mantuvo ambos exited y no invalida la comprobación.

Próximas prioridades, mediante nueva autorización:

1. Corregir DATA-02 y SEC-02 con contratos y regresiones de concurrencia/contexto.
2. Aprobar SEC-03 y snapshot; actualizar Symfony MIME y validar compatibilidad.
3. Completar INF-05: diagnóstico de ventas sin evento, reintento manual auditable,
   métricas de edad/backlog/outbox fallido, workers, Redis/MySQL y alertas económicas.
4. Aceptar staging separado: CA confiable, DNS/SAN, red privada/firewall, recursos
   propios, archivos privados y prueba de restauración/alertas en destino.

El SaaS permanece **NO APTO PARA PRODUCCIÓN**. No se autoriza despliegue, nuevas
migraciones ni escrituras de pruebas por la sola existencia de este informe.

## Estado real del preflight INF-05/F2 (2026-11-03)

- runtime_preflight: PASS
- 36 hashes verificados
- migración F2 aplicada y registrada: ID 71, batch 3
- Redis DB 15 confirmado
- contenedor de inspección: EXIT=0
- entorno aislado de producción: confirmado por `TestingDatabaseGuard` y el esquema `comercios_rd_storage_test`
- PHPUnit: no ejecutado
- migraciones nuevas: no ejecutadas
- seeders: no ejecutados
- build / reconstrucción: no ejecutados
- eliminación de contenedores o volúmenes: no realizada
- datos históricos: no modificados
- SaaS: NO APTO PARA PRODUCCIÓN

### Pruebas funcionales autorizadas para Docker aislado

Se revisaron y se proponen para ejecución exclusiva en Docker aislado, sin tocar la base de producción ni otros esquemas reales:

- `tests/Unit/SaleCreditContractTest.php`
- `tests/Unit/SaleFinancialIntegrityTest.php`
- `tests/Feature/SaleCreditIntegrityTest.php`
- `tests/Feature/FinancialOperationsTest.php`
- `tests/Feature/PosApiTest.php`
- `tests/Feature/ScaleDailySummaryTest.php`
- `tests/Feature/EcfDisabledTest.php`
- `tests/Integration/PostSaleAccountingQueueTest.php`

### Riesgos vigentes

- La validación funcional no se declara cerrada hasta que todas las pruebas requeridas pasen.
- DATA-02 sigue pendiente en cobros, anulaciones, devoluciones y carreras de pagos/sobrepago.
- Las pruebas concurrentes y los timeouts de integración requieren diagnóstico separado antes de cerrar F2.
- El lote no puede afirmar producción-ready ni cerrar requisitos de SaaS multiempresa sin resultados ejecutados reales.

### Comando exacto de ejecución pendiente de autorización explícita

```bash
cd /home/deleon/comercios-rd && \
  sudo docker compose --profile testing --profile accounting-testing \
    --env-file .env.testing.docker -f compose.testing.yaml \
    run -T --no-deps --pull never \
    --name comercios-rd-f2-functional-20261102-1842 \
    phpunit-accounting ./vendor/bin/phpunit \
    --colors=never --testdox \
    --log-junit storage/logs/inf05-f2-functional.junit.xml \
    tests/Unit/SaleCreditContractTest.php \
    tests/Unit/SaleFinancialIntegrityTest.php \
    tests/Feature/SaleCreditIntegrityTest.php \
    tests/Feature/FinancialOperationsTest.php \
    tests/Feature/PosApiTest.php \
    tests/Feature/ScaleDailySummaryTest.php \
    tests/Feature/EcfDisabledTest.php \
    tests/Integration/PostSaleAccountingQueueTest.php
```

> Este comando es el único propuesto para ejecución; no se ha ejecutado aún. No ejecuta migraciones, seeders, builds ni interviene sobre volúmenes existentes.

### Solicitud de autorización

Solicito autorización explícita para ejecutar exclusivamente estas pruebas en Docker aislado, con la base `comercios_rd_storage_test`, Redis DB 15 y sin tocar producción ni recursos históricos. Si se autoriza, la ejecución se limitará a este conjunto y no se realizará ninguna migración ni seeding adicional.

> Informe de auditoría estática y seguimientos autorizados de SEC-01/DATA-01/INF-02/INF-04, con validación aislada aportada por el usuario. No constituye autorización para desplegar, modificar datos reales ni ejecutar nuevas pruebas con escritura.

## Alcance, metodología y límites

- Se audita el **árbol de trabajo actual**, incluidos cambios previos sin confirmar y archivos nuevos. Referencia base: `8f48a7ef797fc4ba51bfd10ced4cfebc6a86ff12`, rama `main`. El commit por sí solo no reproduce este árbol.
- La fase inicial solo permitió lecturas de código/configuración, verificaciones estáticas, consultas a bases públicas de avisos de dependencias y la creación de este documento. Posteriormente se autorizó corregir SEC-01 y, en una nueva petición, endurecer sus invariantes de sucursal/contexto y las ejecuciones concurrentes (DATA-01). El usuario aprobó explícitamente excluir lotes/conteos NULL y exigir sucursal activa concreta.
- **El agente no ejecutó pruebas con escritura ni se conectó a ninguna base de aplicación.** Utilizó verificaciones estáticas y PHPUnit con `--list-tests`. El usuario ejecutó preflights de solo lectura y autorizó separadamente las escrituras aisladas: 36 pruebas/258 aserciones para conteos y 39/399 para INF-02, sin fallos. La ejecución anterior de 14/59 es histórica. El seguimiento INF-02 añadió un Redis efímero exclusivo de pruebas; no se realizaron despliegues, migraciones, seeders, cambios de credenciales reales/dependencias ni modificaciones de datos de desarrollo/producción. El incidente de arranque accidental de la suite general se documenta expresamente en INF-02 y no se usa como validación dirigida.
- El seguimiento posterior INF-04 autorizó una migración nueva exclusivamente aislada y 53 regresiones dirigidas. Tras seis fallos iniciales de enrutamiento y su corrección, la repetición terminó en 53/768 sin fallos. No se usó la suite general. Las notas de pendientes anteriores en las secciones originales se conservan como contexto histórico; este seguimiento cierra únicamente la recuperación de eventos del outbox en el alcance probado. Legado, supervisión y aceptación productiva siguen pendientes.
- No se inspeccionaron manualmente ni se reproducen valores de credenciales de archivos `.env`; la validación de Compose interpola variables sin mostrarlas. La configuración de DigitalOcean, DNS, certificados, políticas reales de Spaces, permisos y contenido actuales de bases y volúmenes no es verificable solamente desde el repositorio.
- Las ejecuciones históricas suministradas por el usuario se distinguen de comprobaciones efectuadas en esta auditoría. Una suite verde no prueba todos los permisos, escenarios de concurrencia o garantías de recuperación.
- No se recuperó un informe anterior íntegro con identificadores de vulnerabilidades. La comparación diferencial usa las doce categorías solicitadas, el código actual y la evidencia histórica disponible, sin inventar detalles de hallazgos anteriores.

Severidades: **CRÍTICO**, **ALTO**, **MEDIO**, **BAJO**. Estados:

| Estado | Significado |
|---|---|
| CORREGIDO Y VALIDADO | El control está implementado y existe evidencia específica verificable para el escenario delimitado. |
| CORREGIDO SIN VALIDAR | El control y/o su prueba existen, pero falta ejecución específica o validación en la capa afectada. |
| PENDIENTE | Persiste una deficiencia demostrable o falta implementar un control necesario. |
| NO VERIFICABLE | Requiere acceso, configuración real, artefactos históricos o ejecución no autorizada disponibles fuera de esta revisión. |

## 1. Resumen ejecutivo

**Dictamen: NO APTO PARA PRODUCCIÓN con la evidencia actual.**

Hay mejoras reales en el contexto de empresa, conteos por sucursal, validación contable y respaldos locales. La protección de portabilidad cuenta con ejecuciones aisladas específicas. SEC-01 y la carrera del mismo conteo DATA-01 recibieron correcciones autorizadas y validación dirigida: 36 pruebas y 258 aserciones, sin fallos. INF-02 se corrigió y validó con otra ejecución autorizada de 39 pruebas/399 aserciones, incluyendo Redis real y workers independientes. Ambas salidas Docker fueron aportadas por el usuario. Persisten defectos de pagos parciales o excesivos concurrentes, aceptación de sucursales desactivadas en otros módulos y ambigüedad de permisos sobre otras operaciones nuevas sin sucursal. La configuración Docker revisada sigue siendo local, no una arquitectura de producción ya validada.

El bloqueo principal no es el número de pruebas: faltan invariantes de autorización e inventario en rutas específicas y evidencia de recuperación/operación en el destino. Composer detectó un aviso **MEDIO** en `symfony/mime`; npm no reportó avisos. No se confirmó una vulnerabilidad **CRÍTICA** en el alcance revisado; esto no certifica ausencia de otras vulnerabilidades ni cobertura exhaustiva.

Prioridad inmediata tras validar SEC-01/DATA-01/INF-02: cerrar las condiciones de carrera y atomicidad de pagos, definir/rechazar escrituras operacionales sin sucursal fuera de conteos y hacer efectiva la desactivación de sucursales en todos los módulos. También faltan TLS de todos los clientes, respaldos externos y configuración operativa de producción, incluida la supervisión y durabilidad reales de colas.

## 2. Estado de seguridad y auditoría diferencial

### 2.1. Comparación de los doce hallazgos solicitados

Las referencias a pruebas en esta tabla acreditan **que existe una regresión implementada**, no que se ejecutó en esta auditoría. Los estados de corrección se limitan al defecto indicado y no a todo el módulo.

| # | Hallazgo, archivo y función | Causa técnica y riesgo actual | Estado actual | Evidencia, prueba y corrección necesaria |
|---|---|---|---|---|
| 1 | `business_id`: resolución de empresa en `BusinessScope`, `BelongsToBusiness` y `TenantContext` | Una empresa de Filament podía prevalecer sobre el contexto explícito del documento y asociar cuentas de otra empresa. Hoy el contexto explícito tiene prioridad y se restaura en `finally`. Los bypass de consola siguen requiriendo predicados explícitos. | **CORREGIDO SIN VALIDAR**, para el defecto de contexto contable; garantía global **NO VERIFICABLE** | `app/Scopes/BusinessScope.php:55-62`; `app/Traits/BelongsToBusiness.php:139-146`; `app/Support/TenantContext.php:38-69`. Regresiones: `FinancialOperationsTest.php:384-451`, venta/gasto con tenant de Filament discordante. Ejecutarlas en aislamiento y ampliar a acciones HTTP/Livewire de cada recurso. |
| 2 | `branch_id`: `BranchAccessContext::build`, `BranchScope::apply`, `TenantPolicy` | Se valida pertenencia y asignación a la empresa/sucursal, pero quedan sucursales inactivas aceptadas y excepciones para NULL fuera de conteos. Aislar una pantalla no prueba aislamiento de todas las escrituras. | **PENDIENTE**, parcialmente corregido | `app/Services/BranchAccessContext.php:93-142`; `app/Scopes/BranchScope.php:47-54`. `BranchAdminScopeTest.php:78-179` cubre vistas y filtros; no cierra SEC-02/SEC-03 globalmente. |
| 3 | Conteos: `StockCountService::open/saveCount/complete/cancel` | Se exige sucursal concreta activa, referencias de empresa/sucursal válidas y saldo local verificable. Se bloquea/recarga el conteo y revalida el estado antes de cualquier transición o ajuste. | SEC-01/DATA-01 **CORREGIDOS Y VALIDADOS** en el alcance probado | `BranchAdminScopeTest.php:350-617` y `StockCountConcurrencyTest.php:67-155`: 36 pruebas/258 aserciones, incluyendo tres contenciones reales entre conexiones independientes. No certifica toda operación externa de inventario. |
| 4 | Contexto inválido: validación de `BranchScope`, `BranchAccessContext::build`, `InventoryService` y `StockCountService` | En conteos se revalidan usuario, negocio, membresía, sucursal activa y contexto inequívoco, sin depender del feature flag. Los otros servicios conservan sus contratos/configuraciones. | **CORREGIDO Y VALIDADO** en conteos; controles anteriores de otros módulos **CORREGIDOS SIN VALIDAR** o **PENDIENTES** | `app/Services/StockCountService.php:341-388`; `BranchAdminScopeTest.php:465-527` prueba nueve contextos inválidos sobre las cuatro operaciones. `InventoryService.php:542-574` y SEC-02 global requieren validación/corrección independiente. |
| 5 | Fail-open: resolución de `BranchAccessContext`, guard de stock de `InventoryService` y bypass de `BusinessScope` | Se eliminó captura silenciosa de fallos de contexto. Permanecen bypass deliberados de consola y un modo documentado de stock compartido. `strict_branch_stock` es opt-in y, aun activo, admite lotes NULL del fondo común. No son por sí mismos un bypass ilegítimo ni aislamiento universal por sucursal. | **CORREGIDO SIN VALIDAR** para la captura eliminada; configuración real **NO VERIFICABLE** | `app/Services/BranchAccessContext.php:43-53`; `app/Services/InventoryService.php:542-574`; `app/Models/Business.php:573-575`; `.env.example:188-205`; `app/Scopes/BusinessScope.php:105-122`. Probar los modos acordados y sus jobs; verificar flags por empresa antes de prometer stock local. |
| 6 | Asientos: `JournalEntryLine::booted`, `AccountingService::createFromSale/createFromExpense`, `JournalEntryService::publish` | Se rechazan cuentas de otra empresa, faltas de cuentas obligatorias y publicación sin equilibrio; existen bloqueos de documentos fuente. Falta demostrar edición/publicación concurrente y guardado completo de relaciones Filament. | **CORREGIDO SIN VALIDAR** para los controles concretos | `app/Models/JournalEntryLine.php:42-79`; `app/Services/AccountingService.php:82-125,193-232,246-324`; `app/Services/JournalEntryService.php:17-52`. Pruebas: `FinancialOperationsTest.php:328-595`. No confundir idempotencia secuencial con concurrencia validada. |
| 7 | Rollback financiero: creación de gastos, devoluciones y `Receivable/Payable::registerPayment` | Hay transacciones en gasto/devolución; las excepciones contables se propagan. Pero registro de pago, recálculo de saldo y caja carecen de transacción/bloqueo común. La contabilidad post-commit y un reembolso manual tampoco tienen garantía de rollback síncrono global. | **PENDIENTE** por DATA-02; rollback del asiento asíncrono **CORREGIDO Y VALIDADO** en su escenario | `CreateExpense.php:24-26`; `QuickActionsWidget.php:141-154`; `FinancialOperationsTest.php:205-224,274-326,523-546`. INF-02: se probó fallo tras crear líneas, rollback completo del asiento y recuperación mediante reintento sin repetir caja/inventario. No revierte la venta ya confirmada. |
| 8 | Respaldos y S3: `PortabilityBackup::handle`, `PortabilityRestoreOperations`, `PortabilityRestore::handle` | Inventario SHA-256, gzip, SQL, tar y destino nuevo están reforzados. S3 se rechaza expresamente, pero no se respalda: falta estrategia externa de objetos y recuperación integral. | **CORREGIDO Y VALIDADO** para primitivas locales delimitadas; S3/recuperación global **PENDIENTES** | Código y pruebas específicas en sección 4; ejecuciones comunicadas 60/231 y 6/47. Probar dump real completo, objetos, claves, aplicación y reconciliación; no asumir que seis fixtures SQL demuestran recuperación del SaaS. |
| 9 | Composer/npm | La consulta actual confirma `symfony/mime 7.4.19` afectado por CVE-2026-45070. No se dispone de todos los avisos/versiones del informe anterior para certificar su cierre individual. | Aviso actual **PENDIENTE**; corrección histórica individual **NO VERIFICABLE** | DEP-01 y sección 8. Cero avisos altos/críticos en ambas consultas y cero avisos npm no equivalen a seguridad lógica ni de imágenes. Actualización acotada propuesta, no ejecutada. |
| 10 | Encabezados HTTP: `SecurityHeaders::handle` y Nginx | Ambos configuran `X-Content-Type-Options`, `Referrer-Policy` y `Permissions-Policy` sin ocultación de los campos FastCGI. El test solo inspecciona Laravel y acepta CSP report-only. Los valores actuales de los duplicados coinciden; no se demostró bypass de política. | **PENDIENTE**, duplicación de configuración confirmada; entrega real **NO VERIFICABLE** | HTTP-01; `deploy/nginx/default.conf:13-15,44-51`; `app/Http/Middleware/SecurityHeaders.php:31-34,49-58`. Establecer un propietario por header y verificar multiplicidad en HTTPS, incluyendo estáticos/errores. |
| 11 | Smoke omitido: `LiveSmoke404Test::test_live_shops_have_no_404_on_core_pages/test_dump_one_500` | Depende de variables e identidades preexistentes; ignora respuestas que pueden representar denegación/redirección. Falsa sensación de cobertura funcional. | **PENDIENTE** | `tests/Feature/LiveSmoke404Test.php:87-94,152-217`. Crear fixtures de roles/empresas/sucursales y expectativas de estado/contenido; no habilitar contra datos reales. |
| 12 | Operaciones `branch_id NULL`: `TracksBranch::bootTracksBranch`, `BranchScope::apply`, `TenantPolicy` | NULL sigue significando histórico, contexto ausente y posible dato de empresa fuera de conteos. Las escrituras de otros módulos pueden producir NULL; la excepción no es solo migratoria. | **PENDIENTE** globalmente; conteos endurecidos y validados | SEC-03; `app/Traits/TracksBranch.php:28-37,57-80`; `database/migrations/2026_09_08_110000_add_branch_id_to_operations.php:32-43`. Conteos/items NULL ocultos (`BranchAdminScopeTest.php:247-292`) y operaciones globales de conteo rechazadas. Falta el resto de tipos operativos. |

### 2.2. Cobertura de módulos sensibles

Esta es una matriz de revisión estática y límites, **no una certificación de que ningún acceso indebido sea posible**. Debe acordarse qué entidades son compartidas por empresa (catálogo, clientes, proveedores, contabilidad) y cuáles pertenecen necesariamente a una sucursal. No debe prohibirse por accidente un catálogo compartido legítimo ni usarse ese catálogo para justificar stock compartido no autorizado.

| Módulo | Archivo/función y control observado | Límite o corrección/prueba faltante |
|---|---|---|
| Usuarios y permisos | `app/Models/User.php:65-94,144-165`; `BusinessUserResource::getEloquentQuery` (`app/Filament/Resources/BusinessUserResource.php:377-447`); edición en `Pages/EditBusinessUser.php:96-159` | Identidad global y membresías de empresa. Validar altas, cambio de rol, contraseña/identidad compartida, usuario inactivo y llamadas Livewire directas; no se acreditó administración de empleados aislada por sucursal. |
| Empresas y sucursales | `EnsureTenantContext::handle` (`app/Http/Middleware/EnsureTenantContext.php:48-113`); restricciones de `BranchResource` (`app/Filament/Resources/BranchResource.php:160-188`) | Se revalida membresía; creación/borrado de sucursales desde tenant están restringidos. SEC-02: desactivación no produce todavía un rechazo fiable. |
| Productos | `BusinessScope` y `BelongsToBusiness`; búsquedas/identidad POS en `PosSessionService.php:312-345,381-398` | Pruebas de producto interempresa existen (`TenantIsolationTest.php:94-160`, `PosApiTest.php:84-92,202-218`); no prueban todas las ediciones/borrados/acciones masivas. Definir catálogo compartido frente a stock local. |
| Inventarios y conteos | `InventoryService.php:61-104,205-295,542-574`; `StockCountService.php:38-132,139-195,216-241,299-315,331-388`; acciones `ViewStockCount.php:36-96` | SEC-01/DATA-01 validados: saldo local, referencias, contexto y una sola finalización con rechazo de estado obsoleto. Falta certificar todas las rutas externas de inventario y los modos de otros servicios. |
| Compras | `PurchaseService::receive` y ciclo de compra (`app/Services/PurchaseService.php:30-68,79-123,137-224`) | Transacción y bloqueo de compra; consultas conservan scopes. Faltan recibos repetidos/concurrentes, IDs ajenos y atribución consistente de lotes/movimientos a sucursal. |
| Ventas | `SaleService` (`app/Services/SaleService.php:114-190,417-474`) | Verificar rollback completo, IDs de otra empresa/sucursal, ventas nuevas sin branch y contabilización asíncrona con configuración real. |
| POS | `BindPosTenant::handle` (`app/Http/Middleware/BindPosTenant.php:19-55`); `PosSessionService.php:312-345,381-398` | Existen controles de membresía/rol e identidad de producto/variante; `PosApiTest.php:202-218` prueba empresa ajena. Falta matriz de sucursales/roles y reintentos de checkout/caja. |
| Clientes | `CustomerResource` (`app/Filament/Resources/CustomerResource.php:251-288`) | El scope retirado para papelera es `SoftDeletingScope`, no el scope de empresa. Entidad compartida por empresa; crear tests negativos de CRUD, selector y acción masiva. |
| Proveedores | `SupplierResource` (`app/Filament/Resources/SupplierResource.php:169-204`) | Mismo límite de negocio compartido; no deducir protección de toda relación por un filtro de navegación. Faltan negativos directos y selección desde compras. |
| Cuentas por cobrar | `Receivable::registerPayment` (`app/Models/Receivable.php:83-156`); acción `ReceivableResource.php:321-343` | Valida importes contra saldo cargado, inserta y recalcula antes de caja, sin transacción/bloqueo común. DATA-02 confirmado; entidad compartida por empresa, no necesariamente de sucursal. |
| Cuentas por pagar | `Payable::registerPayment` (`app/Models/Payable.php:82-142`); acción `PayableResource.php:369-408` | DATA-02: pago/recálculo separados y validación de saldo obsoleto; el archivo incluso puede guardarse antes del pago. La prueba secuencial `FinancialOperationsTest.php:274-326` no cierra estos defectos. |
| Contabilidad | `AccountingService`, `JournalEntryLine`, `JournalEntryService::publish` (`app/Services/JournalEntryService.php:17-52`) | Control de misma empresa y equilibrio implementado; falta publicación versus edición/borrado de líneas y rollback de guardado de relaciones. No afirmar aislamiento por sucursal de un libro de empresa sin un requisito explícito. |
| Devoluciones | `SaleService::returnFromAllocations/canReturn` (`app/Services/SaleService.php:500-575,610-708,712-801`) | Permiso de reembolso, propiedad de asignación y bloqueos presentes. Pruebas implementadas cubren empresa ajena, rol, cantidades y excepción de ledger; falta ejecución específica actual y concurrencia con cobros. El canal manual no es un reembolso automático exitoso. |
| Suscripciones | `SubscriptionBillingService` (`app/Services/SubscriptionBillingService.php:21-75`); `SubscriptionInvoice` (`app/Models/SubscriptionInvoice.php:12-43`) | Modelo global intencional, lookup por empresa y administración desde master. Requiere negativos de acceso tenant a funciones master e idempotencia de facturas concurrentes. |
| Reportes | `ReportsService` (`app/Services/ReportsService.php:75-82,129-149,201-244,278-310`); `DemandForecastService.php:36-51` | Consultas principales usan modelos scoped; agregados enlazan ventas y algunas consultas raw tienen `business_id` explícito. Un filtro de empresa no demuestra filtro de sucursal. Validar cada exportación, rango temporal y contexto local/global. |
| Documentos privados | `PrivateDocumentController.php:19-48`; `TenantDocumentAuthorizer.php:23-43`; `PrivateDocumentStorage.php:30-52,108-158` | Bypass seguido de autorización y comprobación de empresa; prefijos y traversal protegidos. Pruebas `PrivateDocumentAccessTest.php:26-150`. Alcance de sucursal y entrega real Nginx/Spaces pendientes; el fallback público heredado exige protección del servidor/bucket y migración controlada. |

### 2.3. Bypass que no deben confundirse con autorización

`BelongsToBusiness::withoutBusinessScope` solo quita el scope nombrado de empresa (`app/Traits/BelongsToBusiness.php:252-261`); otros scopes pueden seguir activos. `withoutGlobalScopes`, query builder y SQL crudo requieren que cada llamador añada restricciones y autorización pertinentes. Encontrar una de esas APIs no demuestra por sí solo un IDOR: hay que seguir la ruta y sus validaciones.

Los jobs revisados aplican contexto y acotan la fuente por empresa (`app/Jobs/Concerns/AppliesTenantContext.php:14-16`; `app/Jobs/PostSaleAccountingJob.php:53-65`). Los bypass de consola/no autenticados de `BusinessScope.php:105-122` presuponen llamadores confiables. No son una garantía fail-closed universal para futuros comandos o jobs.

`strict_branch_stock` no tiene un valor de entorno global que habilite automáticamente todas las empresas: es una característica guardada por empresa, ausente por defecto (`app/Models/Business.php:573-575`). La documentación permite cualquier lote de la empresa con el modo desactivado y un fondo común NULL con el modo activo (`.env.example:188-198`; `app/Console/Commands/BusinessFeature.php:39-41`). El alta master de administradores de sucursal habilita flags (`app/Filament/Master/Resources/BusinessResource/RelationManagers/BranchesRelationManager.php:150-153`), pero no prueba el estado de empresas existentes. No se consultaron esos valores. Además, `BranchAccessSnapshot.php:27-38` decide restricciones de lectura por el contexto efectivo; no debe confundirse esa decisión con el guard de asignación condicionado al flag en `BranchAccessContext.php:120-121`.

**Excepción estricta autorizada para conteos:** `StockCountService` ahora exige sucursal concreta activa y excluye lotes NULL independientemente de estos flags. El catálogo compartido puede aportar lotes de esa sucursal, pero no un saldo sin lote atribuible al negocio entero. No se alteró automáticamente el contrato de ventas/compras ni se reclasificaron datos del fondo común.

Algunos comentarios de `ProductBatch`/`InventoryMovement` no concuerdan con los traits declarados: prevalecen las declaraciones ejecutables y las eliminaciones explícitas de scopes, no el comentario.

## 3. Vulnerabilidades confirmadas

Estas rutas fueron confirmadas por análisis estático, sin explotación sobre datos reales. SEC-01/DATA-01 incluyen correcciones posteriores autorizadas y regresiones con fixtures aislados ejecutadas por el usuario. Los demás hallazgos no fueron reproducidos dinámicamente en esta revisión. La severidad refleja el efecto y los privilegios necesarios. Los riesgos operativos de la sección 6 no se presentan como evidencia de un despliegue público vulnerable.

### Resumen de hallazgos

| # | Severity | File | Lines | Vulnerability | Confidence |
|---|----------|------|-------|---------------|------------|
| 1 | 🟠 HIGH | app/Services/StockCountService.php | 87-131, 216-241, 331-388 | SEC-01: ajuste intersucursal; CORREGIDO Y VALIDADO | 9/10 |
| 2 | 🟠 HIGH | compose.yaml; config/scale.php | 90-105; 30-34 | INF-02: cola contable no consumida; CORREGIDO Y VALIDADO en el runner aislado | 10/10 |
| 3 | 🟠 HIGH | app/Services/PdoMysqlServerIdentityReader.php | 31-58 | INF-01: TLS autenticado no exigido uniformemente en conexiones independientes | 9/10 |
| 4 | 🟡 MEDIUM | app/Models/Receivable.php; app/Models/Payable.php | 119-156; 112-140 | DATA-02: pagos no atómicos y validación concurrente sobre saldo obsoleto | 9/10 |
| 5 | 🟡 MEDIUM | app/Services/StockCountService.php | 139-195, 299-315, 373-388 | DATA-01: transición concurrente sobre estado obsoleto; CORREGIDO Y VALIDADO | 9/10 |
| 6 | 🟡 MEDIUM | app/Services/BranchAccessContext.php | 125-142 | SEC-02: sucursal desactivada aceptada por el contexto | 9/10 |
| 7 | 🟡 MEDIUM | app/Scopes/BranchScope.php; app/Traits/TracksBranch.php | 47-54; 28-37 | SEC-03: operaciones NULL nuevas entran en excepción histórica; brecha de contrato, no IDOR demostrado | 10/10 |
| 8 | 🟡 MEDIUM | composer.lock | 7592-7593 | DEP-01: Symfony MIME afectado por CVE-2026-45070; alcance explotable de app no demostrado | 10/10 |
| 9 | ⚪ LOW | deploy/nginx/default.conf | 13-15, 44-51 | HTTP-01: duplicación configurada de encabezados Nginx/Laravel; no bypass probado | 10/10 |

ALTO/MEDIO/BAJO corresponden a HIGH/MEDIUM/LOW. SEC-01/DATA-01/INF-02 están **CORREGIDOS Y VALIDADOS** para los escenarios delimitados; los demás hallazgos siguen **PENDIENTES**. SEC-02 está protegido específicamente en conteos, no resuelto globalmente. Las condiciones y límites de INF-01, INF-02, SEC-03 y DEP-01 forman parte del hallazgo, no deben omitirse al resumirlo.

### SEC-01 — ALTO — CORREGIDO Y VALIDADO — confianza 9/10 sobre el defecto original

**Conteo local modifica el stock agregado correspondiente a lotes de otra sucursal.**

- **Archivo/función:** `StockCountService::open/complete/canCountUnbatchedStock/resolveAuthorizedBranch/lockAuthorizedCount`, `app/Services/StockCountService.php:87-131,216-241,331-388`.
- **Causa original:** filtrar lotes por sucursal y recibir cero se interpretaba como “producto sin lotes”. Se usaba entonces `Product.stock`, agregado de empresa, para crear un item local sin `batch_id`. Los productos de catálogo compartido siguen siendo elegibles (`app/Scopes/BranchScope.php:47-54`).
- **Ruta original:** producto compartido con stock 5 y único lote de 5 en B; operador autorizado de A abría conteo y obtenía una expectativa 5 sin lote; registraba 0 y completaba. Se restaba 5 del producto, pero el lote B quedaba en 5.
- **Riesgo:** saldo agregado inconsistente, ajustes originados en una sucursal que afectan existencias de otra y decisiones de reposición/venta incorrectas.
- **Corrección autorizada:** cada operación exige usuario/negocio activos, membresía autorizada y sucursal concreta activa. Se invalida la caché de contexto y se recarga el usuario. El conteo recibido solo aporta su clave: se recarga desde MySQL, con empresa/sucursal del contexto y `FOR UPDATE`, sin confiar en atributos mutables del objeto.
- **Saldos y referencias:** el catálogo compartido sigue disponible, pero solo se toman/ajustan lotes de la empresa, producto y sucursal exactos; se excluyen NULL. El fallback sin lote exige producto asignado a esa sucursal y ausencia de lotes activos en toda la empresa. Se rechazan snapshots heredados ambiguos y productos/lotes ajenos antes de ajustar existencias.
- **Cambio de contrato aprobado:** ya no se permite operar conteos globales/NULL ni ajustar lotes del fondo común NULL. No se movieron ni corrigieron datos históricos; un conteo incompatible queda rechazado. Esta regla no depende de activar un feature flag.
- **Evidencia/prueba:** `tests/Feature/BranchAdminScopeTest.php:350-617` cubre siete escenarios de saldo, nueve contextos inválidos sobre las cuatro operaciones, seis referencias inválidas de producto/lote y dos casos de identidad de conteo falsificada. Conserva además las regresiones anteriores de lectura/propiedad.
- **Validación disponible:** sintaxis PHP, Pint y descubrimiento correctos. Preflight Docker de solo lectura y autorización explícita separados antes de ejecutar: **36 pruebas, 258 aserciones, cero fallos**, incluyendo concurrencia real de DATA-01, PHP 8.3.36/PHPUnit 11.5.57. La salida fue suministrada por el usuario; el agente no tiene acceso al socket Docker ni sudo no interactivo.
- **Límite del cierre:** se verifican exclusión, rechazo, conservación de lotes ajenos y ajustes permitidos en estos flujos. No se ejecutó una suite completa nueva ni se diagnosticaron/repararon datos preexistentes. No certifica todos los servicios externos de inventario.

Comando autorizado ejecutado por el usuario, después de reconstruir la imagen:

```bash
sudo docker compose --profile testing \
  --env-file .env.testing.docker -f compose.testing.yaml \
  run --rm --no-deps phpunit ./vendor/bin/phpunit \
  tests/Feature/BranchAdminScopeTest.php \
  tests/Feature/StockCountConcurrencyTest.php
```

Resultado aportado:

```text
Runtime:       PHP 8.3.36
Configuration: /var/www/html/phpunit.xml
....................................                              36 / 36 (100%)
OK (36 tests, 258 assertions)
```

### SEC-02 — MEDIO — PENDIENTE — confianza 9/10

**El contexto acepta sucursales desactivadas.**

- **Archivo/función:** `BranchAccessContext::build`, `app/Services/BranchAccessContext.php:125-142`; `AuthContextResolver`, `app/Services/AuthContextResolver.php:250-252`.
- **Causa:** se comprueba existencia y pertenencia, no `is_active`. `CheckBranchActivation::handle` elimina la sucursal de sesión (`app/Http/Middleware/CheckBranchActivation.php:41-47`), pero el resolver vuelve a obtenerla del pivot sin validar activación.
- **Ruta:** desactivar una sucursal sin desactivar individualmente todos los pivots/usuarios; el siguiente request recompone el contexto y acepta nuevamente esa sucursal. El orden de middleware está en `app/Providers/Filament/AdminPanelProvider.php:86-90`.
- **Riesgo:** continuar operaciones desde una sucursal revocada/desactivada.
- **Prueba disponible:** los conteos ahora rechazan una sucursal desactivada (`BranchAdminScopeTest.php:465-527`), pero no se validó la siguiente solicitud HTTP/POS de todos los módulos. El resolver global permanece sin corrección.
- **Corrección/cierre:** usar la condición de activa, consistente con `app/Models/Branch.php:55-58`, en toda resolución. Probar HTTP y POS con sesión previa y pivot aún activo; rechazar, no ampliar silenciosamente a “todas las sucursales”.

### SEC-03 — MEDIO — PENDIENTE — confianza 10/10 sobre el comportamiento; divulgación no autorizada no probada

**NULL permite nuevas operaciones sin distinguir datos compartidos de ausencia de contexto.**

- **Archivo/función:** `BranchScope::apply`, `app/Scopes/BranchScope.php:47-54`; `TenantPolicy`, `app/Policies/TenantPolicy.php:79-86`; `TracksBranch`, `app/Traits/TracksBranch.php:28-37,57-80`.
- **Causa:** excepción de visibilidad `sucursal autorizada OR branch_id IS NULL`; el tratamiento especial que ya rechaza NULL en conteos no abarca todas las operaciones.
- **Ruta demostrada:** el propietario en vista global resuelve branch NULL (`app/Services/AuthContextResolver.php:233-247`) y puede crear un gasto desde `QuickActionsWidget.php:141-154` o `CreateExpense.php:14-26`; el formulario (`ExpenseResource.php:47-108`) no exige seleccionar sucursal. El registro nuevo queda incluido en la excepción NULL.
- **Contrato existente:** `TenantPolicy.php:79-86` y `.env.example:200-205` describen una excepción histórica, no una autorización explícita de gasto global nuevo. `BranchTelemetryObserver` considera los nuevos NULL una contaminación indeseada del fondo común/reportes, pero solo registra avisos (`app/Observers/BranchTelemetryObserver.php:14-21,41-68`); está registrado también para gastos (`app/Providers/AppServiceProvider.php:77-81`).
- **Riesgo:** ampliar visibilidad de operaciones a otras sucursales o perder atribución operativa. **No se presenta como fuga entre empresas ni como prueba de que todo gasto global sea ilegítimo**: falta un contrato explícito de qué datos globales están autorizados y para quién.
- **Prueba disponible:** `BranchAdminScopeTest.php:247-292` cubre NULL de conteos/items; el seguimiento también rechaza conteos globales y lotes NULL. No cubre el contrato de NULL en ventas, gastos, compras o sesiones nuevas.
- **Corrección/cierre:** definir datos deliberadamente globales con autorización explícita y exigir sucursal válida para operaciones locales. Separar el caso histórico del actual; primero inventario de datos de solo lectura y plan compatible, nunca backfill destructivo automático.

### DATA-01 — MEDIO — CORREGIDO Y VALIDADO — confianza 9/10 sobre el defecto original

**Dos finalizaciones concurrentes podían aplicar dos veces la diferencia del conteo.**

- **Archivo/función:** `StockCountService::saveCount/complete/cancel/lockAuthorizedCount`, `app/Services/StockCountService.php:139-195,299-315,373-388`.
- **Causa original:** se comprobaba el estado sobre el objeto recibido antes de la transacción, sin bloquear/recargar/revalidar el conteo. Los locks de producto/lote no evitaban dos ajustes.
- **Intercalado original:** expectativa 10, conteo 12; A y B leían “en progreso”; A sumaba 2; B obtenía luego el lock y sumaba los mismos 2: resultado 14, no 12.
- **Riesgo:** stock artificial y movimientos duplicados por doble envío/reintento o solicitudes concurrentes.
- **Evidencia:** los índices de referencia (`database/migrations/2026_07_18_150151_create_inventory_movements_table.php:39-52`) y esquema de conteos (`database/migrations/2026_08_10_160000_create_stock_counts_tables.php:39-51`) no garantizan ajuste único por item.
- **Corrección:** las tres operaciones bloquean y recargan el mismo padre dentro de su transacción y revalidan el estado. Finalizar también bloquea los items y ordena productos/lotes; los cambios y movimientos se confirman juntos. Un reintento sobre un conteo ya completado se rechaza sin duplicar movimientos; no se devuelve éxito silencioso.
- **Prueba:** `tests/Feature/StockCountConcurrencyTest.php:67-155` y `tests/Fixtures/stock-count-concurrent-worker.php:20-79` usan dos conexiones MySQL con IDs distintos. El padre completa y mantiene abierta su transacción; el competidor acredita contención con error 1205 y, tras commit, reintenta con el objeto obsoleto. Finalizar, guardar y cancelar son rechazados; se verifica un solo movimiento, lote local 12, lote ajeno 5 y agregado 17. Los tres casos están incluidos en 36 pruebas/258 aserciones sin fallos.
- **Aislamiento y límites:** nueve tablas vacías por caso clonadas solo de estructura, prefijo aleatorio `crd_count_test_<hex>_`, sin copiar datos ni claves foráneas hacia tablas originales. Se eliminan únicamente las tablas cuya creación confirmó el propio test. No se validaron todos los intercalados entre conteos distintos y otros servicios. La apertura simultánea aún puede competir por el código secuencial: el índice único rechaza la colisión, sin reparación/reintento automático implementado.

### DATA-02 — MEDIO — PENDIENTE — confianza 9/10

**Pagos de cuentas por cobrar/pagar pueden quedar parciales, exceder el saldo o desactualizarlo.**

- **Archivos/funciones:** `Receivable::registerPayment` (`app/Models/Receivable.php:119-156`) y `Payable::registerPayment` (`app/Models/Payable.php:112-140`); recálculos en `Receivable.php:83-105` y `Payable.php:82-99`.
- **Causa:** se comprueba el saldo ya cargado, se inserta el pago y los observers `saved` recalculan el padre mediante lecturas y escrituras separadas (`app/Models/ReceivablePayment.php:60-61`; `app/Models/PayablePayment.php:60-61`). En la cuenta por cobrar, caja se modifica después. No hay transacción común ni bloqueo/recarga del padre antes de validar.
- **Sobrepago concurrente:** dos solicitudes validan 70 contra el mismo saldo 100; ambas pueden insertar, dejando pagos por 140.
- **Saldo cacheado obsoleto:** A calcula agregado 40; B inserta otro pago y guarda agregado 100; A escribe después su 40, aunque todos los pagos siguen en la tabla. Saldo/estado y pagos divergen.
- **Commit parcial:** la inserción puede persistir antes de que falle recálculo o caja; la interfaz informa error y un reintento puede duplicar el pago.
- **Llamadores:** acciones de tabla `app/Filament/Resources/ReceivableResource.php:321-343,370-378` y `app/Filament/Resources/PayableResource.php:369-408,437-444`, y edición `Pages/EditReceivable.php:26-43` / `Pages/EditPayable.php:27-45`, no proporcionan bloqueo/transacción que cierre el defecto. El archivo de una cuenta por pagar puede persistirse antes de registrar pago (`PayableResource.php:383-396`). Las transacciones de acción Filament están desactivadas por defecto (`vendor/filament/actions/src/Concerns/CanUseDatabaseTransactions.php:10-30`), sin habilitación en las acciones revisadas.
- **Precisión:** `CashSessionService` incrementa caja atómicamente; **no se afirma pérdida de un incremento de caja**. Su `lockForUpdate` (`app/Services/CashSessionService.php:293`) no crea una transacción global sobre pago, padre y caja.
- **Prueba disponible:** `FinancialOperationsTest.php:274-326` valida pagos secuenciales e importes inválidos, no estas carreras/fallos.
- **Corrección/cierre:** una transacción con padre bloqueado/recargado, saldo revalidado, efectos completos e idempotencia de solicitud. Inyectar fallo tras inserción y antes de caja/recálculo; ejecutar pagos concurrentes con dos conexiones al servidor desechable autorizado.
- **Procedencia:** defecto de archivos sin cambios previos en este árbol; no atribuirlo a las correcciones recientes.

### HTTP-01 — BAJO — PENDIENTE — confianza 10/10 sobre la configuración candidata

**Encabezados duplicados en la configuración Nginx/Laravel.**

`deploy/nginx/default.conf:13-15` añade `X-Content-Type-Options`, `Referrer-Policy` y `Permissions-Policy`, también configurados en `SecurityHeaders::handle` (`app/Http/Middleware/SecurityHeaders.php:31-34,49-58`). La ubicación PHP hereda las directivas (`deploy/nginx/default.conf:44-51`) y no hay `fastcgi_hide_header`, `proxy_hide_header` ni un solo propietario. `headers->set()` de Symfony no reemplaza los campos que Nginx añade después.

Los valores actuales coinciden: se confirma duplicación configurada, **no un bypass de seguridad demostrado**. En imágenes públicas existe además doble emisión de `Cache-Control` por `expires` y `add_header` (`default.conf:33-37`); no es evidencia de cacheo de una respuesta autenticada.

**Cierre:** un propietario explícito por encabezado conservando protección de estáticos/errores, y prueba de multiplicidad a través de Nginx/HTTPS. El test Laravel actual no cubre esa capa; la entrega real no se capturó en esta auditoría.

**DEP-01**, componente Composer afectado, se detalla en la sección 8. Su versión vulnerable está confirmada; la explotabilidad dentro de esta aplicación no.

## 4. Vulnerabilidades corregidas y verificadas

La corrección de los simuladores que se ejecutaban en `/tmp` está validada por la salida Docker suministrada por el usuario: `60 tests, 231 assertions`, sin fallos, después de reconstruir la imagen. Los simuladores se crean ahora en directorios únicos de `storage/framework/cache`, no requieren ejecutar archivos desde `/tmp` y se limpian en `tearDown` (`tests/Unit/PortabilityRestoreOperationsTest.php:17-29`; `tests/Unit/PortabilityWorkflowTest.php:35-66`).

La ejecución adicional del servicio de integración aislado terminó con `6 tests, 47 assertions`. Sus escenarios están definidos en `tests/Integration/PortabilityMysqlRestoreTest.php:70-137`. Las dos ejecuciones recientes cubren 66 casos y 278 aserciones; no representan una nueva ejecución completa del SaaS.

| Control implementado | Código | Evidencia específica y frontera |
|---|---|---|
| SEC-01: contexto activo y concreto, referencias locales, saldo verificable y rechazo de snapshots ambiguos | `app/Services/StockCountService.php:87-131,216-241,331-388` | `BranchAdminScopeTest.php` incluido en ejecución autorizada de 36 pruebas/258 aserciones. No certifica todos los módulos ni repara datos previos. |
| DATA-01: serializar guardar/finalizar/cancelar y rechazar estado obsoleto | `app/Services/StockCountService.php:139-195,299-315,373-388` | Tres casos reales con sesiones independientes y contención acreditada en `StockCountConcurrencyTest.php`; incluidos en 36/258. |
| INF-02: consumo de cola contable, despacho tras commit e idempotencia de reentregas | `compose.yaml`; `config/queue.php`; `app/Jobs/PostSaleAccountingJob.php`; transacción existente de `AccountingService::createFromSale` | 39 pruebas/399 aserciones sin fallos; once nuevas con Redis real, procesos MySQL independientes, fallo parcial, reintento por UUID, concurrencia y caída tras commit/antes de ACK. No acredita operación productiva ni un outbox duradero. |
| Integridad de los payloads locales, rechazo de inventario incompleto, gzip/SQL incompleto y tar inseguro | `app/Services/PortabilityRestoreOperations.php:55-165` | `PortabilityRestoreOperationsTest` en el grupo Docker de 60 pruebas. No autentica el origen del respaldo ni prueba todas sus invariantes de negocio. |
| Propagación de fallos gzip/MySQL | `app/Services/PortabilityRestoreOperations.php:44-52` | Pruebas de ambos componentes simulados y primitivas reales en `PortabilityMysqlRestoreTest.php:113-137`. |
| Rechazo de base activa/existente y destino de archivos exclusivo | `app/Services/PortabilityRestoreTargetGuard.php:17-44`; `app/Console/Commands/PortabilityRestore.php:99-163` | `PortabilityCommandTest` y workflow en grupo 60; integración comprueba base vacía preexistente y no vacía. No prueba atomicidad frente a caída del host. |
| Publicar solo un respaldo local validado | `app/Console/Commands/PortabilityBackup.php:46-118` | Workflow con clientes simulados y verificaciones reales de archivos. Falta un dump/restauración integral con datos del esquema completo y objetos externos. |

El grupo dirigido de INF-02 también ejecutó las 18 regresiones existentes de `FinancialOperationsTest`, cinco de `ScaleDailySummaryTest` y cinco de `EcfDisabledTest`. Su resultado acredita esos escenarios concretos, no todos los permisos ni todas las operaciones financieras. DATA-02 y la autorización global siguen pendientes; no se infiere su cierre por una suite verde.

## 5. Hallazgos pendientes

| Pendiente | Severidad | Estado y evidencia requerida |
|---|---|---|
| DATA-02, SEC-02 y SEC-03 | MEDIO | **PENDIENTE**; pagos atómicos, activación global y contrato de NULL fuera de conteos. SEC-01/DATA-01 ya tienen validación dirigida, no global. |
| Integridad financiera end-to-end y concurrencia | ALTO como riesgo de preparación | DATA-02 confirmado; garantía global **NO VERIFICABLE**. Exigir pruebas de todos los pasos y modos de cola, no solo importes secuenciales. |
| Operación productiva de colas tras INF-02 | ALTO como requisito de operación | Enrutamiento e idempotencia **CORREGIDOS Y VALIDADOS** en Docker aislado (39/399). Configuración efectiva, supervisión, alertas y recuperación ante pérdida de Redis en destino **PENDIENTES**; no hubo despliegue. |
| TLS autenticado de todos los clientes MySQL | ALTO como bloqueo de transporte | **PENDIENTE** en el código de conexiones independientes; negociación/configuración desplegada **NO VERIFICABLE**. |
| Estrategia externa de objetos y recuperación completa | ALTO como riesgo de pérdida de datos | **PENDIENTE**; copia privada fuera del host, claves recuperables, retención y simulacro RPO/RTO. |
| HTTPS, perfil productivo, monitoreo y recuperación operativa | ALTO como bloqueo de despliegue | Artefactos y evidencia **PENDIENTES**; entorno real **NO VERIFICABLE**, no se aprovisionó. |
| DEP-01: Symfony MIME | MEDIO | **PENDIENTE**; parche compatible propuesto, sin actualización automática. |
| Smoke funcional y configuración de seguridad de producción | MEDIO | **PENDIENTE**; fixtures y aserciones de autorización reales, no crawl de comercios existentes. |
| HTTP-01 y entrega de encabezados | BAJO para duplicados configurados | **PENDIENTE** configuración de propietario único; entrega de cadena Nginx/TLS/balanceador **NO VERIFICABLE**. |
| Cobertura medida y seguridad de imágenes | MEDIO | **NO VERIFICABLE**; no hay informe de cobertura/SBOM/escáner que sostenga aprobación. |

## 6. Estado de infraestructura

`compose.yaml:1-30` declara un entorno **local**, con nombres y configuración locales y respaldos deshabilitados. No se identificó un artefacto de producción aprobado con evidencia de operación; no se debe promover el Compose local cambiando solo el nombre del archivo o `APP_ENV`.

| Componente | Evidencia estática | Estado para producción |
|---|---|---|
| Compose y endurecimiento | `compose.yaml:48-55,139-146`: solo lectura, capacidades retiradas y `no-new-privileges`. Configuración declarada local. | Controles presentes que deben conservarse; perfil/configuración productiva **PENDIENTE**. No quitar `noexec` para hacer pasar pruebas. |
| Nginx/entrada pública | `deploy/nginx/default.conf:1-3`: HTTP; `compose.yaml:147-148`: publicación en loopback. | Adecuado como componente local/interno, no prueba de HTTPS de producción. Terminación TLS, redirección y cadena de proxy **NO VERIFICABLES**. |
| PHP-FPM | `docker/php/www.conf:1-17`: pool dinámico, 12 children, arranque/min spare 2, max spare 4, reciclaje 500, slowlog 5 s, terminate 130 s y stderr. `docker/php/zz-app.ini:1-14`: memoria 256 MB, ejecución 120 s, OPcache sin validar timestamps; `Dockerfile:108-109,124-127`: `www-data`, FPM foreground. | Configuración presente; dimensionamiento/carga y coherencia de timeouts **NO VERIFICABLES**. OPcache exige release inmutable y reinicio coordinado, no edición en caliente del código. |
| MySQL | `compose.yaml:171-233`: backend sin puerto MySQL publicado en host. | Preservar aislamiento. Definir autogestionado frente a Managed MySQL; permisos, capacidad, TLS, backups y HA reales **NO VERIFICABLES**. |
| Redis | `compose.yaml:19-23,202-217`: usuario/password vacíos, sin autenticación de servidor declarada; AOF, snapshot `60 1`, volumen `/data`; backend interno (`231-233`). | **PENDIENTE** decidir/endurecer ACL y segmentación de mínimo acceso para producción y política de eviction/separación de propósitos. No está publicado al host: no se afirma exposición pública. AOF local no equivale a HA ni respaldo externo. |
| Workers | `compose.yaml:90-112`: colas compartidas explícitas, 3 intentos, timeout 90 s, gracia 120 s, memoria de ciclo 256 MB y reinicio supervisado. Redis reserva por defecto 180 s (`config/queue.php:67-73`); `Dockerfile` instala `pcntl`. | INF-02 **CORREGIDO Y VALIDADO** en aislamiento: consumo real, fallos, reintentos y caída antes del ACK en 39/399. Compose y nombres personalizados comprobados estáticamente. Supervisión, apagado normal y progreso del destino productivo no verificados. |
| Scheduler | `compose.yaml:109-119`: requiere un perfil explícito. | **PENDIENTE** activación productiva, exclusión mutua, supervisión y verificación de ejecuciones; no basta con habilitar la variable de respaldos. |
| HTTPS/proxy/sesiones | Middleware HSTS condicionado a HTTPS, `app/Http/Middleware/SecurityHeaders.php:60-86`. | **NO VERIFICABLE** TLS real, confianza del proxy, cookies y encabezados entregados. No habilitar HSTS definitivo sin HTTPS correcto. |
| Variables y secretos | Se revisaron ejemplos y mecanismos, no valores secretos reales. | **NO VERIFICABLE** configuración efectiva; exigir `APP_DEBUG=false`, clave estable y respaldada, cookies seguras y credenciales de mínimo privilegio, sin incorporarlas al informe/imagen. |
| Almacenamiento privado | `deploy/nginx/private-storage.conf:3-20`; inclusión `deploy/nginx/default.conf:40-42`; control de aplicación en módulo de documentos. | Defensa local presente. Entrega real, rutas heredadas y políticas de bucket **NO VERIFICABLES**. |
| Spaces | Driver S3 disponible; `PortabilityBackup.php:29-36` rechaza el respaldo local si S3 requiere una estrategia aparte. | **PENDIENTE** estrategia de objetos, bucket privado, permisos, entrega autorizada y prueba de recuperación/versionado. No declarar resuelto por existir el driver. |
| Respaldos | Publicación local validada y rechazo explícito de S3; preflight exige estrategia (`SecurityPreflight.php:128-136`). | **PENDIENTE** copia externa, cifrado, retención, programación monitorizada y verificación periódica. |
| Recuperación | Destino de restauración nuevo y limpieza de recursos propios. | **PENDIENTE** ensayo integral SQL+objetos+claves+aplicación y reconciliación; las primitivas aisladas no acreditan RPO/RTO. |
| Logs | `compose.yaml:9,65-69,165-169,192-196,221-225`: stderr de aplicación y `json-file` de 10 MB × 3, heredado por worker/scheduler. | Rotación local configurada; recolección externa, censura de secretos, conservación de auditoría y entrega de alertas **NO VERIFICABLES**. |
| Monitoreo | No hay evidencia operativa suministrada de alarmas probadas. | Alertar por errores, espacio, edad del último respaldo, restauraciones fallidas, cola retrasada y tareas ausentes; **PENDIENTE**. |
| Healthchecks | `compose.yaml:74-83,98-107,120-128,157-164,184-191,213-220`: TCP FPM, `/up`, cmdline de worker/scheduler, ping MySQL y Redis. | **PENDIENTE** readiness de cuenta/esquema de aplicación, progreso real y backup fresco. No publicar diagnósticos internos; un ping no prueba persistencia/recuperación. |
| Recursos | `compose.yaml:39-238`: sin límites declarados de CPU, memoria de contenedor o PIDs. Worker `--memory=256` no es un límite cgroup; scheduler declara una réplica (`118-119`). | **PENDIENTE** límites/dimensionamiento de producción y prueba de carga; su eficacia en el destino **NO VERIFICABLE**. |
| Reinicio automático | `compose.yaml:45,136,173,200`: `unless-stopped`, heredado por app/worker/scheduler. | Política presente; recuperación real **NO VERIFICABLE**. No reinicia automáticamente un proceso que sigue vivo pero permanece unhealthy/atascado. |
| Imágenes | Versiones/etiquetas de construcción no equivalen a escaneo de capas o SBOM. | **PENDIENTE** inventario inmutable de release, escaneo y política de parcheo de sistema/PHP/Nginx/MySQL/Redis. |

### INF-01 — ALTO — PENDIENTE: TLS de conexiones MySQL independientes

`config/database.php:49-70` permite CA en PDO de la conexión de aplicación. Sin embargo, `PdoMysqlServerIdentityReader::target` crea otra conexión con error mode/timeout pero sin heredar esas opciones (`app/Services/PdoMysqlServerIdentityReader.php:31-58`). Los archivos de cliente de `PortabilityBackup::mysqlCnf` (`app/Console/Commands/PortabilityBackup.php:145-160`) y `PortabilityRestore::mysqlCnf` (`app/Console/Commands/PortabilityRestore.php:201-222`) tampoco trasladan explícitamente CA/verificación de identidad.

No se declara que una conexión desplegada esté actualmente en texto plano: no fue inspeccionada y los clientes/servidor pueden negociar cifrado por defecto. El defecto comprobable es que la aplicación no exige de forma uniforme autenticación TLS del servidor en esas rutas. Comparar `@@server_uuid` no autentica criptográficamente el endpoint.

**Cierre:** propagar CA y verificación de hostname a PDO y a ambos clientes, con opciones compatibles MySQL/MariaDB; probar rechazo de CA no confiable/hostname incorrecto y la conexión legítima solo contra infraestructura desechable. Utilizar cuentas admitidas por el servicio administrado y mínimo privilegio.

### INF-02 — ALTO — CORREGIDO Y VALIDADO en el alcance aislado — confianza 10/10 sobre la causa original

**Causa original:** el worker omitía `--queue` y solo escuchaba `default`, mientras `PostSaleAccountingJob::__construct` selecciona `scale.queues.sales` (por defecto `sales`). El ejemplo productivo activa `SCALE_POST_SALE_QUEUE=true`. Al reutilizar ese worker, una venta podía confirmarse y dejar su asiento pendiente aunque el healthcheck del proceso siguiera verde. Timeout y reserva de 90 s tampoco tenían margen.

**Corrección implementada:**

- `compose.yaml` comparte `SCALE_QUEUE_SALES`, `SCALE_QUEUE_REPORTS`, `REDIS_QUEUE`, `SCALE_POST_SALE_QUEUE` y `REDIS_QUEUE_RETRY_AFTER` entre app, worker y scheduler. El worker consume las tres colas, incluidos nombres personalizados. El modo local sigue siendo síncrono por defecto.
- Reserva Redis de 180 s, timeout de 90 s y gracia de apagado de 120 s. Se conservan reinicio automático, límites del worker y endurecimiento de los contenedores.
- `PostSaleAccountingJob::dispatchFor` conserva el comportamiento productivo de despacho después del commit y elimina únicamente su excepción para tests: ahora la prueba recorre la misma garantía. El modo inline no cambia.
- No se reescribió `AccountingService::createFromSale`: ya bloquea la venta, comprueba empresa/referencia dentro de la transacción, revierte asiento/líneas al fallar y propaga la excepción al worker. La idempotencia debe validarse con entregas repetidas y fallos reales, no asumirse.
- `.env.production.example`, `docs/deploy.md` y `docs/staging-setup.md` documentan colas, tiempos, supervisión y reintento individual por UUID. Se permite versionar únicamente la plantilla de placeholders; no se modificaron credenciales reales.

**Pruebas ejecutadas y aprobadas:** once casos en `tests/Integration/PostSaleAccountingQueueTest.php` y el proceso auxiliar `tests/Fixtures/post-sale-accounting-worker.php`: venta/stock/caja/resumen intactos, despacho tras commit, rollback sin job, modo inline, duplicados, fallo tras crear líneas, tres intentos y `failed_jobs`, `queue:retry`, aislamiento multiempresa y cuentas, venta anulada, muerte del worker tras commit antes del ACK y entregas concurrentes con contención.

El perfil opt-in `accounting-testing` de `compose.testing.yaml` hereda el MySQL aislado y añade `test-redis`, sin puertos publicados, persistencia ni conexión a la red local de aplicación. Cada caso crea 16 copias vacías con prefijo aleatorio `crd_accounting_test_<hex>_`, usa un namespace Redis propio y ejecuta workers PHP independientes. Solo limpia tablas/colas de ese caso. No usa migraciones, seeders ni copias de datos existentes. El usuario autorizó expresamente este DDL, las escrituras/limpieza de fixtures y las 28 regresiones existentes con transacciones revertidas.

**Evidencia estática:** cinco archivos PHP cambiados con sintaxis válida y Pint correcto; `git diff --check` correcto. Ambos Compose validan. La configuración resuelta comprueba herencia del runner, comando predeterminado de solo lectura, red interna y ausencia de puertos; otra verificación con nombres de colas personalizados confirma igualdad entre productores y worker y margen de tiempos. Estas comprobaciones no conectaron al daemon Docker ni a bases.

**Incidente durante la preparación manual:** al copiar una línea, el nombre `test-redis` quedó separado de `up --no-deps`. Compose inició también `phpunit-accounting`, que heredaba `php artisan test`: el usuario aportó logs de 54 pruebas aprobadas, 190 advertencias por lectura de `.env` ausente y 1244 aserciones. No era una ejecución autorizada ni la validación dirigida de estas once regresiones; no se utiliza para cerrar INF-02. El usuario confirmó la detención del runner `e2ff11a21d3d`. No se solicitó revertir datos ni borrar volúmenes. Como prevención, el comando predeterminado del nuevo servicio ahora es `tests/Fixtures/accounting-queue-preflight.php`, de solo lectura; PHPUnit exige un comando explícito posterior a la autorización.

**Identidad verificada antes de autorizar la ejecución dirigida:** proyecto `comercios-rd-isolated-tests`; red `comercios_rd_isolated_test_backend`, `internal=true`; volumen `comercios_rd_isolated_test_mysql`, sin puertos publicados de MySQL/Redis. MySQL 8.4.13: contenedor `ef3c89813c5c`, host configurado `test-mysql`, UUID `bfd92f30-d1c5-11f1-beb5-b24b172266ee`, esquema `comercios_rd_storage_test`, cuenta `comercios_storage_test@%`, guard de identidad/grants **PASS**, sin tablas de referencia faltantes. Redis 7.4.11: contenedor `1a271a30edc6`, host `test-redis`, DB 15, PING correcto y `run_id=e9ed5873032ba8a975943f1abfc31b206ffeb262`, coincidente entre el preflight y la consulta dentro del contenedor.

**Autorización y resultado:** el usuario autorizó las 39 pruebas y la creación/limpieza exclusiva de fixtures/tablas/colas propias; después aportó la salida de `phpunit-accounting-run-db0398bc3d48`, PHP 8.3.36/PHPUnit 11.5.57: **OK (39 tests, 399 assertions)**, 34.910 s, memoria 74.50 MB. El agente no accedió al socket Docker ni usó sudo. No se cambiaron archivos funcionales después de esa ejecución.

**Cierre de recursos temporales:** el usuario detuvo exclusivamente `test-redis` (`1a271a30edc6`) y confirmó estado `exited`. El runner accidental ya estaba detenido; no se detuvo MySQL ni se eliminaron contenedores o volúmenes.

Comando dirigido autorizado:

```bash
sudo docker compose \
  --profile accounting-testing \
  --env-file .env.testing.docker \
  -f compose.testing.yaml \
  run --rm --no-deps \
  phpunit-accounting ./vendor/bin/phpunit \
  tests/Integration/PostSaleAccountingQueueTest.php \
  tests/Feature/FinancialOperationsTest.php \
  tests/Feature/ScaleDailySummaryTest.php \
  tests/Feature/EcfDisabledTest.php
```

**Riesgos operacionales que no resuelve este cambio:** el Compose sigue siendo local, no un despliegue productivo certificado. Deben verificarse supervisión, caché de configuración y variables reales en destino, progreso de colas y alertas. Redis no forma una transacción atómica con MySQL; perder Redis o fallar su publicación después del commit puede requerir reconciliación. El comando existente de reconciliación revisa como máximo 500 ventas en una ventana de 24 horas por ejecución; no equivale a un outbox duradero ni garantiza recuperación completa de una interrupción prolongada. Cambios manuales de referencia o eliminación de asientos quedan fuera de la garantía de reentrega de una venta sin mutaciones.

Si muere el runner completo durante una prueba, podrían quedar sus tablas/colas aleatorias; solo deben revisarse esos nombres propios, nunca aplicar limpieza amplia. Los 39 casos completaron sin errores de limpieza.

### Validación del runner de pruebas, no del despliegue productivo

La configuración aislada de pruebas fue validada sin usar el daemon Docker:

```bash
docker compose --profile testing --profile portability-testing \
  --env-file .env.testing.docker -f compose.testing.yaml config --quiet
```

Resultado: configuración válida. En la fase inicial no se ejecutó `up`, `run`, `build`, `exec`, `down` ni se inspeccionó una base activa. El seguimiento SEC-01 autorizó posteriormente solo `build` y `run` del runner aislado, ejecutados por el usuario.

### Restricciones del destino DigitalOcean verificadas en documentación pública

La documentación oficial consultada declara soporte de Managed MySQL 8.4, ausencia de acceso `root`, PITR limitado a siete días y restauración en un nodo nuevo. Un endpoint privado solo es accesible desde la VPC correspondiente; no equivale por sí mismo a TLS con autenticación del servidor. Exigir CA y verificación de identidad tanto en PDO como en los clientes de respaldo/restauración, y limitar las fuentes confiables a los servicios necesarios.

La selección de edición, tamaño, región y réplicas debe quedar explícita: la documentación vigente anuncia restricciones de planes Standard que afectan memoria y nodos de respaldo. No extrapolar alta disponibilidad a cualquier plan económico. Si se elige MySQL autogestionado en un Droplet, no se heredan automáticamente las garantías de Managed Databases.

Fuentes oficiales: [límites de MySQL](https://docs.digitalocean.com/products/databases/mysql/details/limits/), [conexiones y CA](https://docs.digitalocean.com/products/databases/mysql/how-to/connect/) y [fuentes confiables y conexiones cifradas](https://docs.digitalocean.com/products/databases/mysql/how-to/secure/). No se accedió a ninguna cuenta o API de infraestructura.

## 7. Estado de pruebas y cobertura

### Evidencia disponible y límites

| Evidencia | Resultado | Interpretación |
|---|---|---|
| Suite completa anterior comunicada por el usuario | 155 aprobadas, 807 aserciones, 2 omitidas | Histórica; no prueba el árbol actual ni cada hallazgo individual. |
| Portabilidad: unitarias + Feature, salida Docker suministrada | 60 aprobadas, 231 aserciones | Evidencia específica después de corregir las rutas de los simuladores. |
| Integración MySQL aislada, salida suministrada | 6 aprobadas, 47 aserciones | Esquemas aleatorios desechables y escenarios de importación acotados. |
| SEC-01: corrección inicial de saldo, ejecución anterior | 14 aprobadas, 59 aserciones | Histórica; el contrato posterior más estricto se valida en la fila siguiente, no sumando resultados. |
| SEC-01/DATA-01: `BranchAdminScopeTest.php` y `StockCountConcurrencyTest.php` actuales | 36 aprobadas, 258 aserciones | Ejecución aislada autorizada, salida aportada por el usuario tras rebuild y preflight. Incluye los tres casos de concurrencia entre sesiones distintas; no es la suite completa. |
| INF-02: integración Redis y regresiones contables/post-venta | 39 aprobadas, 399 aserciones | Once nuevas y 28 existentes; Docker aislado, identidad/grants verificados y autorización nueva. Incluye workers independientes, contención, reintento por UUID y muerte tras commit/antes de ACK. No es validación operativa en DigitalOcean. |
| Suite general arrancada accidentalmente durante la preparación | 54 aprobadas, 190 advertencias, 1244 aserciones | Incidente documentado en INF-02; no autorizado como ejecución de validación. No se utiliza para cerrar bloqueadores. El perfil ahora arranca un preflight de lectura por defecto. |
| Inventario estático anterior al seguimiento INF-02 | 39 archivos `*Test.php`, 194 métodos `test_` | Histórico; INF-02 añadió un archivo de integración con once métodos. Métodos y casos ejecutables no son equivalentes: los proveedores amplían escenarios. |
| Comprobación sintáctica inicial | 574 archivos PHP válidos | `app`, `config`, `routes`, `tests`, `database/migrations`; no ejecuta la lógica. |
| Comprobación estática del seguimiento estricto | Cuatro archivos PHP válidos, Pint y `git diff --check` correctos | Servicio, regresiones Feature y worker auxiliar; las escrituras fueron validadas separadamente por el usuario. |
| Métrica de cobertura de líneas/ramas | NO VERIFICABLE | No se generó cobertura ni se encontró un informe de cobertura/CI que la acreditase. |

No se deben sumar las suites históricas: pueden solaparse y corresponden a revisiones diferentes.

### Runner e aislamiento

- `phpunit.xml:27-38` fuerza `APP_ENV=testing`, conexión MySQL, esquema `comercios_rd_storage_test` y `DB_URL` vacío. `DB_HOST` no está forzado: por sí solo el XML no acredita aislamiento físico.
- `config/database.php:6,53-60` selecciona credenciales `DB_TEST_USERNAME`/`DB_TEST_PASSWORD` en testing, en vez de reutilizar las credenciales normales.
- `tests/TestCase.php:11-17` invoca `TestingDatabaseGuard::assertIsolated` antes de entregar la aplicación a los tests Feature. La aplicación ya se ha inicializado; no es una barrera contra efectos de un proveedor durante su arranque.
- `app/Support/TestingDatabaseGuard.php:33-97` rechaza ambiente/conexión/esquema/URL incorrectos, credenciales vacías o placeholders, grants globales, grants fuera del esquema y `GRANT OPTION`. Consulta identidad y grants al inicializar los tests: el usuario aportó primero `ISOLATED_RUNNER_OK` desde el preflight de solo lectura y, después de autorización nueva, 36 casos sin rechazo del guard.
- `docker/testing/restrict-test-user.sql:1-5` restringe el usuario ordinario al esquema de pruebas. Los cambios de ese archivo solo se aplican automáticamente cuando se inicializa un volumen nuevo; el permiso real de un volumen ya existente no se deduce únicamente del archivo.
- `compose.testing.yaml:1-24,123-131` define proyecto, red y volumen exclusivos; red `internal: true`, sin publicar el puerto MySQL. Es la frontera de aislamiento prevista, junto con la verificación de identidad/grants en las ejecuciones anteriores.
- `compose.testing.yaml:102-121` separa la integración que requiere root. `tests/Integration/PortabilityMysqlRestoreTest.php:23-67,139-156` exige habilitación expresa, host `test-mysql`, esquema de referencia y crea nombres aleatorios `crd_restore_test_*_copy`; solo elimina destinos cuya creación confirmó. No sustituir este servicio por una conexión real ni conceder root al runner ordinario.
- La integración no forma parte de las suites predeterminadas de `phpunit.xml:7-15`; debe ejecutarse expresamente con su servicio aislado.
- INF-02 usa un perfil `accounting-testing` separado, con Redis propio y preflight predeterminado de solo lectura. Las once pruebas nuevas usan copias vacías aleatorias y rechazan otros endpoints; no necesitan root, migraciones ni datos preexistentes. Se validaron junto con 28 regresiones existentes, tras identidad y autorización nuevas descritas en INF-02.
- La configuración de `/tmp` no fue relajada. No se inspeccionaron los flags efectivos de los tmpfs del runner; la inspección actual del volumen MySQL no sustituye esa comprobación operativa futura.

Preflight anterior de SEC-01/DATA-01 comunicado por el usuario, antes de autorizar sus escrituras: `project=comercios-rd-isolated-tests`, `service=test-mysql`, `image=mysql:8.4`, red `comercios_rd_isolated_test_backend`, volumen `comercios_rd_isolated_test_mysql`, `published={}`. El guard confirmó esquema y grants restringidos. Aquel build comunicó manifest list `sha256:af8a73471a7f73b2977d3014be47b0ca23e1f0ce478807855486f74c0cce7176`. La identidad y ejecución posteriores de INF-02 están documentadas en su apartado; no se inspeccionaron las imágenes de un despliegue real.

Las pruebas concurrentes no usan root ni migraciones: `StockCountConcurrencyTest.php:27-64` crea y elimina exclusivamente copias vacías con nombre aleatorio, manteniendo las tablas originales intactas. Si se mata abruptamente el runner, podrían quedar esas tablas nuevas; no se implementó ninguna limpieza amplia o automática de posibles residuos.

### Omisiones y pruebas insuficientes

- `tests/Feature/LiveSmoke404Test.php:87-94,196-200` omite dos pruebas salvo habilitación explícita mediante variables de entorno. El crawler usa identidades y comercios preexistentes en lugar de fixtures controlados.
- `tests/Feature/LiveSmoke404Test.php:152-194` considera fallos esencialmente los estados 404/500: redirecciones a login, 403 y otros errores pueden no detectar una pantalla inaccesible. No es una prueba funcional ni una demostración de autorización correcta.
- No ejecutar ese crawler contra comercios reales: solicitudes GET pueden generar sesiones, auditoría o cambios de contexto. Sustituirlo por fixtures desechables, estados/roles esperados y verificaciones de contenido; tratar el test de diagnóstico como herramienta, no como criterio de aceptación.
- `phpunit.xml:43-55` desactiva varios controles y rutas de ejecución para el runner ordinario: 2FA, fingerprint, CSP, cifrado de sesiones y procesamiento asíncrono. Existen pruebas que reactivan controles concretos, pero faltan resultados con la combinación de configuración de producción.
- `tests/Feature/SecurityHeadersTest.php:9-30` inspecciona la respuesta Laravel. No atraviesa Nginx, TLS o un balanceador y no demuestra ausencia de encabezados duplicados en el borde.

### Condición obligatoria antes de futuras pruebas con escritura

Se requiere una autorización nueva y explícita, comprobar primero la red/instancia/volumen aislados y los grants, y limitar las pruebas a fixtures y esquemas desechables. No utilizar desarrollo ni producción, no ejecutar seeders sobre bases existentes ni comandos destructivos o eliminar volúmenes. Este documento no otorga esa autorización.

## 8. Dependencias vulnerables y plan compatible

### Auditorías ejecutadas

```bash
composer --no-plugins --no-scripts audit --locked --format=json --no-interaction
npm audit --package-lock-only --ignore-scripts --json \
  --registry=https://registry.npmjs.org
composer --no-plugins --no-scripts depends symfony/mime --locked
```

No se ejecutó `update`, `install`, `npm audit fix` ni scripts de proyecto. Los SHA-256 de los cuatro manifiestos/lockfiles no cambiaron. Los metadatos de los 163 paquetes Composer instalados coinciden con el lockfile; los 89 nodos npm instalados que tienen metadatos locales tampoco presentan diferencias de versión con el lockfile. Esto no inspecciona imágenes desplegadas.

| Ecosistema | Resultado actual | Estado |
|---|---|---|
| Composer | Código de salida 1; un aviso MEDIO; cero avisos altos/críticos y cero paquetes abandonados | PENDIENTE |
| npm | Código de salida 0; cero avisos, incluidos altos/críticos, en el lockfile consultado | Sin avisos conocidos en esta consulta; no garantía universal de seguridad |

**DEP-01 — MEDIO — PENDIENTE — confianza 10/10 sobre la versión afectada.**

- Paquete: `symfony/mime v7.4.19`, `composer.lock:7591-7593`.
- Aviso: `CVE-2026-45070`, `PKSA-wtxr-p26d-nn42`, inyección de encabezados por nombres de parámetros MIME que no cumplen el formato token.
- Causa: no validar ciertos nombres de parámetros al serializar encabezados estructurados. La explotación requiere que el atacante controle el **nombre** de un parámetro, no solamente el nombre de archivo o su valor.
- Riesgo: inyección CRLF de encabezados si existe esa ruta de entrada. La aplicación contiene una versión afectada; **no se demostró una ruta de explotación en Comercios RD**.
- Versión corregida compatible: `7.4.22` o posterior mantenida de la rama 7.4. El aviso oficial aclara que `7.4.12`, previamente anunciado como corregido, no contenía realmente la corrección.
- Compatibilidad declarada: Laravel `12.70.1` requiere `symfony/mime ^7.2.0`; Mailer `7.4.19` admite `^7.2|^8.0`. Se propone mantener Symfony 7.4 y PHP 8.3; no cambiar de versión mayor como parte de este parche.
- Fuente oficial consultada: <https://symfony.com/cve-2026-45070>.

Plan propuesto, **no ejecutado**:

1. Revisar la resolución con `composer update symfony/mime --dry-run --no-scripts --no-plugins`, en una rama de corrección y sin ejecutar scripts del proyecto.
2. Autorizar después una actualización acotada del lockfile a una versión 7.4 corregida; ampliar dependencias solo si el solver acredita que es necesario.
3. Revisar el diff completo y la ausencia de cambios mayores, regenerar la imagen de pruebas y verificar el aviso con una auditoría nueva.
4. Con autorización expresa para escritura, ejecutar regresiones aisladas de correo/notificaciones/documentos y la suite afectada. La prueba del componente debe rechazar CR/LF en nombres de parámetros; no hace falta atacar un servicio real.
5. No modificar npm actualmente solo por esta auditoría: su lockfile no presenta avisos conocidos. Mantener Node compatible con Vite y `laravel-vite-plugin` (`^20.19.0 || >=22.12.0`) y revisar futuras alertas.

Las bases de avisos son temporales y no analizan automáticamente lógica SaaS, sistema operativo, imágenes, plugins externos o configuración real. No se encontraron Trivy/Grype instalados; el análisis de imágenes queda pendiente.

## 9. Riesgos de pérdida o corrupción de datos

| Riesgo | Mecanismo y efecto | Acción de reducción y evidencia necesaria |
|---|---|---|
| Stock agregado contra lotes | SEC-01/DATA-01 corregidos; corrupción histórica y flujos externos no diagnosticados | Regresiones dirigidas aprobadas. No “reconciliar” datos existentes sin diagnóstico autorizado; ampliar la evidencia a conteos distintos, ventas, compras y devoluciones concurrentes. |
| Operaciones sin contexto inequívoco | SEC-02/SEC-03 y modos de stock: sucursal revocada, NULL ambiguo o modo compartido no documentado | Resolución fail-closed para operaciones locales, definición de datos globales y tratamiento separado de legado. |
| Errores financieros parciales | DATA-02 confirma falta de atomicidad/bloqueo en pagos; además, las transacciones locales no acreditan el flujo completo y post-commit en cola no puede deshacer el commit original | Corregir pago/padre/caja con una transacción e idempotencia; definir compensación/outbox y reconciliación de lo asíncrono. |
| Contabilidad en espera o reintentada | INF-02 corregido/validado con 39/399; operación y durabilidad del destino no acreditadas | Verificar variables, supervisión y alertas reales del destino. No asumir durabilidad atómica entre MySQL y Redis ni recuperación ilimitada del comando actual. |
| Reembolsos manuales | `SaleService.php:712-801` puede registrar un resultado de reembolso manual si falla caja | No contabilizarlo como pago efectivo realizado; alertar y mantener una cola/procedimiento de conciliación verificable. |
| Consistencia de SQL y archivos | Un dump transaccional SQL no congela simultáneamente archivos, objetos o todas las tareas externas | Definir quiescencia o snapshot coordinado y reconciliar archivos/documentos con la base; probar escrituras concurrentes de forma controlada. |
| Fallo total del host/volumen | Un respaldo solo local comparte dominio de fallo con la aplicación | Copias privadas fuera del host, retención y recuperación demostrada desde una máquina/entorno limpio. |
| S3/Spaces no respaldado | El comando local rechaza S3; no existe evidencia de una estrategia integral de objetos | Copia/versionado/retención compatibles con Spaces y verificación de restauración de bytes y metadatos; no asumir soporte de características S3 no verificadas en el proveedor. |
| Pérdida de claves | Un fingerprint de `APP_KEY` en el manifiesto no permite recuperar secretos cifrados ni sustituye la clave | Custodia separada, cifrada y recuperable de claves/configuración; ensayo sin exponer claves ni copiarlas al repositorio. |
| Restauración interrumpida | MySQL DDL hace commits implícitos. La limpieza normal de la copia nueva no se ejecuta tras todo SIGKILL/fallo del host ni garantiza que `DROP` tenga éxito | No conectar usuarios a la copia hasta el cierre; marcar/quarantinar restos, verificar limpieza y documentar recuperación manual de recursos exclusivamente propios. |
| Autenticidad del respaldo | SHA-256 detecta modificación accidental, no autentica a quien genera SQL y su archivo de checksums | Tratar solo respaldos de origen confiable, protegerlos frente a modificación y verificar procedencia; mínimo privilegio y entorno de recuperación aislado. No ejecutar SQL arbitrario contra una cuenta de producción privilegiada. |
| Transporte y cliente de dump | INF-01; CA no propagada a todas las conexiones y compatibilidad real del cliente/definers/rutinas/eventos no demostrada con el esquema completo | TLS autenticado y simulacro del dump real con cliente admitido, permisos mínimos y reconciliación de objetos. |
| Recursos/colas/disco | Sin prueba de capacidad y alertas, el proceso puede fallar después de admitir operaciones o dejar tareas pendientes | Límites dimensionados, backpressure, retry/idempotencia, rotación y alertas de espacio/colas/respaldos probadas. |

No se ejecutó ninguna consulta para cuantificar corrupción ya existente: este informe identifica rutas de riesgo, **no afirma que los datos actuales estén corruptos**.

## 10. Plan de corrección priorizado

Esta lista requiere autorización separada. El usuario autorizó SEC-01, su endurecimiento estricto y la concurrencia asociada DATA-01, así como la validación MySQL aislada: completados para los escenarios documentados. La existencia del plan no autoriza las demás tareas ni nuevas pruebas con escritura.

| Orden | Tarea | Prioridad / cierre exigido |
|---|---|---|
| 1 | Fijar el contrato de autorización por entidad, empresa, sucursal y rol; listar todas las rutas sensibles HTTP/Livewire/servicio/job y los datos compartidos intencionales | P0: impedir fugas/accesos no autorizados. Matriz aprobada y casos negativos definidos; no usar la mera ocultación de navegación como control. |
| 2 | SEC-01: corrección estricta autorizada completada y validada, sin migraciones/backfills | **CORREGIDO Y VALIDADO**: ejecución de 36 pruebas/258 aserciones. Usuario/negocio/sucursal activos, claves persistidas, referencias locales, sin conteos/lotes NULL; saldo sin lote solo del producto asignado. No repara datos antiguos. |
| 3 | DATA-01: guardar/cancelar/finalizar coordinados bajo bloqueo | **CORREGIDO Y VALIDADO**: tres contenciones reales de sesiones independientes, sin doble ajuste ni modificación de conteo cerrado. El resto de combinaciones de inventario requiere evidencia separada. |
| 4 | Hacer efectiva la desactivación de sucursal y resolver SEC-03/semántica NULL | P0: rechazos de sucursal inactiva/contexto ausente y separación de datos globales. Pruebas para cada operación nueva; cualquier saneamiento de legado requiere plan y autorización propios. |
| 5 | Corregir DATA-02 y demostrar atomicidad/concurrencia de cobros, pagos, devoluciones, caja, gastos y publicación contable | P0: evitar pérdidas/saldos incorrectos. Bloquear/recargar/revalidar antes de insertar, probar fallo en cada paso y dos pagos simultáneos, reintentos, cancelación y edición/publicación. |
| 6 | Completar negativos de acceso de todos los módulos y acciones masivas/directas | P0: actor A no lee/modifica/borra B ni una sucursal fuera de su permiso. Comprobar también relaciones anidadas, documentos y exportaciones, membresía revocada y contexto de jobs. |
| 7 | Tras INF-02 validado, proteger transporte/recuperación: INF-01, HTTP-01 y perfil productivo de Nginx/TLS/proxy | INF-02 cerrado en su alcance aislado (39/399). P0 antes de exposición: comprobar configuración/supervisión y progreso del worker en destino, CA/hostname correctos, cuentas limitadas y servicios internos no publicados; encabezados únicos. |
| 8 | Diseñar e implementar copias externas privadas y recuperación SQL+objetos+claves | P0 antes de datos reales: retención/RPO/RTO aprobados, origen confiable y simulacro completo con reconciliación; no asumir que el respaldo local cubre Spaces. |
| 9 | Aplicar, previa aprobación, el parche acotado DEP-01 y volver a auditar lockfiles/imágenes | P1: Symfony MIME corregido sin saltos mayores ni actualizaciones masivas; regresiones autorizadas y diff de dependencias revisado. No actualizar npm sin motivo demostrado. |
| 10 | Completar artefacto productivo reproducible: roles web/worker/scheduler, recursos, persistencia, reinicio, logs, healthchecks y alertas | P1: pruebas de proceso detenido, cola retrasada, disco bajo, tarea ausente y último respaldo envejecido en entorno desechable; imágenes/SBOM identificables. |
| 11 | Sustituir smoke heredado y medir cobertura relevante; ejecutar suite actual con perfil de seguridad/colas de producción | P1: autorización explícita e aislamiento verificado antes de escribir; ningún smoke de aceptación omitido, controles reactivados y resultados archivados por imagen/revisión. |
| 12 | Preparar un staging DigitalOcean independiente y solicitar autorización de aprovisionamiento y despliegue en otra fase | Solo después de cerrar diseño/bloqueadores: no usar datos/credenciales reales, validar restauración y rollback, obtener aprobación formal antes de producción. |

Cada cierre debe asociar el ID del hallazgo, diff revisado, prueba dirigida que falla sin el cambio, comando autorizado, imagen/revisión y resultado. Una excepción aceptada requiere responsable, plazo y control compensatorio explícitos; no se convierte en “corregido”.

## 11. Criterios de aceptación para producción

| Criterio obligatorio | Evidencia verificable de aceptación |
|---|---|
| Autorización por empresa/sucursal/rol | Matriz aprobada y negativos de lectura/creación/edición/borrado/exportación/documentos/acciones masivas. La operación denegada no genera cambios observables. |
| Contexto y aislamiento de inventario | SEC-01/SEC-02/SEC-03/DATA-01 cerrados con pruebas dirigidas; stock agregado/lotes/saldos locales consistentes; ausencia de autorización no amplía consultas o escrituras. |
| Finanzas | DATA-02 cerrado: saldo y validación bajo lock; pago/padre/caja atómicos; ningún asiento publicado desequilibrado o con cuenta ajena; flujos fallidos conservan invariantes y reintentos son únicos/conciliables. |
| Branch NULL y legado | Política explícita de datos globales frente a operaciones locales; no se crean operaciones locales sin sucursal válida; tratamiento del legado diagnosticado y autorizado separadamente. |
| Pruebas e aislamiento | Autorización de escritura nueva, runner en red/base exclusivas, usuario limitado, resultados de la suite actual y regresiones dirigidas; smoke de aceptación no omitido. Cobertura de ramas sensibles documentada, sin inventar un porcentaje. |
| Configuración productiva | Artefacto separado de lo local, secretos fuera de imagen/repositorio, debug apagado y servicios internos no publicados. Roles, flags de stock/colas/seguridad y capacidad declarados. |
| TLS y navegador | HTTPS real, cadena y cookies correctas, CA+hostname en todos los clientes MySQL y encabezados coherentes/únicos en el borde, también en errores. CSP report-only no se confunde con política aplicada. |
| Archivos privados y Spaces | Pruebas de no acceso directo al objeto, autorización por contrato de empresa/sucursal, traversal rechazado, bucket privado y mínimos permisos; verificación de rutas heredadas antes de darlas por seguras. |
| Recuperación | Simulacro completo en entorno nuevo: SQL, objetos, claves y aplicación; conciliación de saldos/documentos/archivos; limpieza/quarantina de fallos, copias externas y RPO/RTO definidos y medidos. |
| Dependencias/artefactos | DEP-01 cerrado; sin avisos altos/críticos abiertos salvo aceptación formal; revisión del lock, auditoría reciente, SBOM y escaneo de las imágenes exactas del release. |
| Operación | INF-02 cerrado: workers atienden todas las colas productoras y reserva supera timeout con margen; scheduler avanza realmente, reinicio/apagado verificados, recursos/persistencia suficientes, logs sin secretos y alertas recibidas ante fallos simulados. |
| Gobierno del release | Ningún hallazgo crítico/alto pendiente ni control obligatorio sin verificar; revisión técnica, evidencia vinculada al artefacto y autorización explícita de despliegue. |

## 12. Dictamen final

**NO APTO PARA PRODUCCIÓN CON LA EVIDENCIA ACTUAL.**

Las mejoras de portabilidad, conteos y colas permiten continuar la preparación, pero no cierran los defectos restantes de sucursal/inventario y pagos ni demuestran autorización completa o recuperación/operación en DigitalOcean. INF-02 quedó corregido y validado en Docker aislado, no desplegado ni certificado en el destino. No hay base verificable para garantizar que ningún usuario pueda operar datos de otra sucursal en todos los módulos ni para prometer recuperación integral.

El proyecto puede avanzar a correcciones controladas y, posteriormente, a staging aislado tras autorización. No debe recibir aprobación de lanzamiento por acumular suites verdes de revisiones distintas.

**Cambios y límite de autorización:** la auditoría inicial solo generó este documento. Los seguimientos de inventario modificaron `app/Services/StockCountService.php`, ampliaron `tests/Feature/BranchAdminScopeTest.php` y añadieron `tests/Feature/StockCountConcurrencyTest.php` y `tests/Fixtures/stock-count-concurrent-worker.php`: 36 pruebas/258 aserciones sin fallos. El seguimiento INF-02 modificó exclusivamente despacho/configuración de colas, sus guías y pruebas directamente relacionadas: `.gitignore`, `.env.production.example`, `app/Jobs/PostSaleAccountingJob.php`, `config/queue.php`, `compose.yaml`, `compose.testing.yaml`, `docs/deploy.md`, `docs/staging-setup.md`, `tests/Integration/PostSaleAccountingQueueTest.php`, `tests/Fixtures/post-sale-accounting-worker.php` y `tests/Fixtures/accounting-queue-preflight.php`, además de este informe. Su validación autorizada posterior al preflight terminó en 39/399. No se reescribió contabilidad ni se actualizaron dependencias, modificaron datos de desarrollo/producción o realizaron despliegues. SEC-01/DATA-01/INF-02 quedan **CORREGIDOS Y VALIDADOS** en los alcances documentados; los demás bloqueadores y el dictamen **NO APTO PARA PRODUCCIÓN** permanecen.
