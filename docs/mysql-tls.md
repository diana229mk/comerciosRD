# INF-06: TLS autenticado para MySQL

**Estado: CORREGIDO Y VALIDADO en Docker aislado.** El lote INF-01 pasó
72 pruebas/281 aserciones y cuatro checks Oracle el 2026-10-31.
No acredita configuración productiva ni habilita staging/producción.

## Contrato aprobado y compatibilidad

- Con `APP_ENV=production`, las conexiones TCP del driver Laravel `mysql`
  requieren una CA legible y verificación de identidad del servidor.
  `MYSQL_SSL_REQUIRED=false` no desactiva esta obligación.
- `MYSQL_SSL_REQUIRED=true` exige la misma política en otros entornos.
  Configurar `MYSQL_ATTR_SSL_CA` también activa la verificación.
- Desarrollo/testing sin CA ni exigencia explícita conserva la conexión
  existente sin TLS obligatorio. Un socket Unix explícito solo se admite
  cuando TLS no es requerido. Se rechaza `localhost` en el modo obligatorio
  para impedir que PDO seleccione un socket implícito.
- PDO Laravel y el lector PDO independiente usan CA y
  `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true`. El conector comprueba
  `Ssl_cipher` antes de configurar la sesión. Una conexión sin cifrado no
  se devuelve a la aplicación.
- Los archivos privados de backup/restore incluyen CA, protocolo TCP y
  verificación. Oracle MySQL 8/9 usa `ssl-mode=VERIFY_IDENTITY`; MariaDB
  usa `ssl=1` y `ssl-verify-server-cert=1`. Versiones no reconocidas
  fallan sin recurrir a un cliente no verificador.
- Los clientes de portabilidad leen `--defaults-file`, no
  `--defaults-extra-file`: un `.my.cnf` ambiental no puede sobrescribir
  los controles. Los valores se entrecomillan y se rechazan saltos de línea
  y NUL antes de crear el temporal 0600. Las contraseñas no van en argumentos.
- El driver Laravel `mariadb` no fue modificado; no confundirlo con el
  binario MariaDB utilizado para conectarse al servidor MySQL.

No se modificaron archivos `.env`, credenciales reales, servidores
preexistentes o configuraciones desplegadas. Se inicializaron/recrearon solo
los recursos TLS efímeros con autorización. La obligatoriedad de TLS en producción
es un cambio fail-closed aprobado: un despliegue TCP sin CA dejará de conectar.

## Requisitos para un despliegue futuro

Montar en lectura una CA confiable emitida por el proveedor, en la misma
ruta dentro de web, workers, scheduler y runners de backup/restore. Configurar
`MYSQL_ATTR_SSL_CA` con esa ruta y `DB_HOST` con un nombre cubierto por el
certificado; no sustituirlo por una IP sin SAN correspondiente. No montar
claves privadas del servidor en la aplicación.

En staging exigir también `MYSQL_SSL_REQUIRED=true` si no se utiliza
`APP_ENV=production`. La preparación y recarga de configuración necesitan
autorización independiente; este trabajo no las ejecuta.

Mantener el hogar de los procesos de portabilidad sin `.mylogin.cnf` ajenos:
Oracle puede leer login paths incluso con `--defaults-file`. El check Oracle
aislado añade `--no-login-paths`; los comandos operativos mantienen el
contrato de archivo privado existente. Antes de aceptar un runtime Oracle,
comprobar que sus login paths no redirijan usuario/host respecto al destino
verificado. El cifrado con identidad no sustituye el guard de UUID/esquema.

`security:preflight --strict` comprueba el requisito y la ruta de CA, pero no
prueba por sí solo una negociación real, vigencia del certificado, grants,
redes o configuración de DigitalOcean. La aceptación requiere conexión
autenticada y rechazo de CA incorrecta, hostname discordante y servidor sin TLS.

## Pruebas e aislamiento

El perfil opt-in `tls-testing` añade dos servidores MySQL 8.4 nuevos, sin
puertos publicados y en la red interna `comercios_rd_isolated_test_backend`:

- `test-mysql-tls`: TLS obligatorio con certificado SAN `test-mysql-tls`;
  alias `wrong-mysql-tls` para probar identidad discordante.
- `test-mysql-no-tls`: `--tls-version=` para deshabilitar protocolos.
  No usa `--ssl=OFF`, eliminado en MySQL 8.4. Su disponibilidad y ausencia
  de cifrado deben comprobarse realmente antes de ejecutar casos negativos.

Ambos usan `/var/lib/mysql` en tmpfs de hasta 1 GiB, esquema
`comercios_rd_storage_test`, cuenta aislada y el init SQL restrictivo
existente. No se modifica el MySQL persistente de INF-04 ni su volumen.

`tls-certificates` genera certificados de siete días en el volumen dedicado
`comercios_rd_isolated_test_tls_certs_sha256`; nunca sobrescribe los artefactos.
Las claves de CA no se guardan. La clave del servidor es exclusivamente
desechable y legible por el UID MySQL del otro contenedor; `client.cnf`
contiene solo la credencial de pruebas y se crea 0600. El volumen debe
tratarse como secreto aislado, no como artefacto de producción ni Git.

`mysql-tls-preflight.php` verifica host, CA, guard, grants de ambos servidores,
UUID distintos y cipher; imprime identidades y hash de CA, no contraseñas.
El runner TLS es independiente del runner base: no hereda el perfil `testing`
ni una dependencia del MySQL persistente. Usar servicios explícitos y
`--no-deps`, nunca arrancar el perfil completo sin revisión.

Las nuevas pruebas cubren política fail-closed, socket local compatible,
opciones no verificadoras anuladas, inyección de opciones rechazada, PDO
Laravel/independiente y clientes MariaDB de backup/restore ante los tres
fallos TLS. Los casos positivos prueban que un `.my.cnf` ambiental no puede
deshabilitar TLS. Un dump solo de estructura del esquema vacío no importa
datos ni ejecuta migraciones. El script Oracle realiza consultas de lectura.
Las regresiones existentes de portabilidad se seleccionan explícitamente;
las pruebas root de restauración real y la suite general quedan excluidas.

## Autorización y evidencia pendiente

La aprobación del contrato y de los cambios de código **no autoriza**
inicializar servidores o ejecutar pruebas con escritura. Primero se requiere
autorizar creación de los recursos efímeros y generación de certificados.
Después, revisar IDs, imagen, red interna, tmpfs, ausencia de puertos,
UUID, cuenta, grants y cifrado con el preflight. Solo entonces solicitar
autorización de los archivos PHPUnit seleccionados.

El agente no dispone de acceso al socket Docker; las operaciones privilegiadas
las realiza el usuario. No reutilizar como identidad actual los UUID de
INF-04. No iniciar/detener servicios existentes ni borrar volúmenes.
La validación autorizada terminó en 72 pruebas/281 aserciones y cuatro checks
Oracle, descritos al final. Las nuevas ejecuciones necesitan aprobación propia.

## Incidente de preparación: build y healthcheck

El primer build con solo `--profile testing` falló: `phpunit-accounting`
hereda ese perfil del runner base, pero su dependencia Redis solo está en
`accounting-testing`. No se reconstruyó la imagen. El generador siguiente
usó la imagen antigua y no encontró `mysql-tls-certificates.php`. Se creó el
volumen TLS, pero no hay evidencia de certificados emitidos.

Para construir exclusivamente `phpunit`, habilitar `testing` y
`accounting-testing` en el comando **build**, sin `up`/`run` general.
Esto resuelve la validación de dependencias sin iniciar Redis ni otros
servicios. Encadenar build y generación con `&&`; no continuar tras un fallo.

El usuario inició los dos MySQL nuevos con el healthcheck anterior de
`mysqladmin ping`. Su estado Healthy solo acredita proceso alcanzable,
no autenticación, CA ni cifrado. Se endureció exclusivamente el servidor TLS:
entrypoint que rechaza fixtures ausentes/vacíos y healthcheck con usuario
aislado, `VERIFY_IDENTITY` y cipher no vacío. En ese momento, el cambio no estaba aplicado
al contenedor ya iniciado; un nuevo build no actualiza su configuración.
No recrear/reiniciar ese contenedor sin revisar y autorizar el efecto sobre
su tmpfs. No tocar el MySQL persistente ni borrar volúmenes.

El build corregido y encadenado terminó correctamente el 2026-10-31;
generador `407d92b9d15a`: `TLS_TEST_CERTIFICATES_CREATED`. La emisión ya
está acreditada, pero no prueba que el MySQL iniciado antes haya cargado
esos certificados. Primero comprobar por socket local identidad/grants y
número de tablas de aplicación del esquema dedicado. Esa lectura no es
una prueba TLS. Reaplicar entrypoint/healthcheck recrearía el contenedor
y podría perder su tmpfs: requiere autorización explícita posterior.

## Corrección de la emisión SHA-1

El diagnóstico de lectura `d87b68278d49` confirmó CA y certificado RSA-SHA1,
firmas matemáticas correctas, SAN correcto y clave coincidente. El generador
omitía `digest_alg`, por lo que la configuración de firma utilizada por PHP
emitió SHA-1. MySQL rechazó la validación TLS aunque la comprobación de
propósito/cadena del PHP aislado devolviera true.

Se exige ahora SHA-256 explícito en solicitudes y firmas de CA, CA negativa
y servidor. Antes de exportar, se comprueban algoritmo RSA-SHA256, firmas y
correspondencia de clave. No se rebaja el nivel de seguridad de OpenSSL ni
se elimina `VERIFY_IDENTITY`.

La nueva emisión usa `comercios_rd_isolated_test_tls_certs_sha256`. El
volumen original `comercios_rd_isolated_test_tls_certs` se conserva intacto
con los artefactos SHA-1 y el archivo privado de pruebas: no borrarlo,
sobrescribirlo ni reutilizarlo para producción. El contenedor TLS actual
monta ahora SHA-256 tras la recreación expresamente autorizada.

La emisión nueva, su comprobación por lectura y la recreación exclusiva se
autorizaron por separado, tras renovar identidad/grants y verificar cero
tablas de aplicación en el tmpfs que se descartaría.

## Cierre de validación: 2026-10-31

Preflight `eb7200c8098a`: guard PASS para ambos servidores. TLS
`c36aa03c2cb8`, UUID `a7fc925d-d537-11f1-b477-4e3466c53ed3`,
MySQL 8.4.13, cipher `TLS_AES_256_GCM_SHA384`; CA SHA-256
`e82e02cbc2e78e8e26ab639017f8d9e2162641f629b70021633322593028dbf0`.
Servidor negativo `56f4d9543e32`, UUID
`25344cd0-d4ee-11f1-8e7e-221f058f8bef`, cipher vacío y grants exclusivos.
Red interna, sin puertos publicados, datadir tmpfs y certificados montados ro.

Runner `ea6efdcd57d9`: **OK (72 tests, 281 assertions)**, PHP 8.3.36,
PHPUnit 11.5.57, 4.804 s, 64.50 MB. Archivos seleccionados:
`MysqlTlsPolicyTest`, `MysqlTlsTest`, `PortabilityWorkflowTest` y
`PortabilityRestoreOperationsTest`. Check Oracle: cuatro PASS, incluyendo
rechazo de CA/hostname incorrectos y servidor sin TLS.
No suite general, Feature/Financial, migraciones, seeders o importaciones.
No cambios funcionales posteriores al resultado.

Pint/PHP lint de catorce archivos, sintaxis de dos scripts shell,
Compose testing/producción y `git diff --check`: aprobados.
Las comprobaciones estáticas de Compose no accedieron al daemon.
Los clientes PHP/MariaDB y el check Oracle se validaron en las identidades
aisladas anteriores; no se certifica un runtime Oracle con login paths ajenos,
ni el driver Laravel `mariadb`, ni restauración integral con datos reales.

INF-01 queda cerrado en este alcance; INF-05, pagos concurrentes,
sucursales inactivas, contrato NULL, costo inmutable y Symfony MIME siguen
pendientes. El SaaS permanece **NO APTO PARA PRODUCCIÓN**.

Los volúmenes SHA-1/SHA-256 se conservan, ambos con material privado de pruebas.
El usuario autorizó y ejecutó la parada de los dos MySQL efímeros:
`c36aa03c2cb8` y `56f4d9543e32`, ambos verificados **exited / RUNNING=false**
el 2026-10-31. Contenedores y volúmenes conservados; el cierre no incluyó
MySQL persistente/Redis. No borrar los volúmenes. La fecha de expiración
de certificados es 2026-11-07.
