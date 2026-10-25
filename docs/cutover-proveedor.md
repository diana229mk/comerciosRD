# Cutover de proveedor — ComerciosRD

Objetivo: mover **toda** la plataforma (todos los negocios) a otro host
sin reonboardar comercios ni resetear contraseñas.

No se migra tienda por tienda. Una sola base compartida + archivos.

## Antes de producción

```bash
php artisan portability:check
php artisan portability:backup
```

En el `.env` de producción:

```
PORTABILITY_BACKUP_ENABLED=true
PORTABILITY_BACKUP_DIR=/var/backups/comerciosrd
```

Cron del scheduler (cada minuto; el backup corre a las 02:15 UTC vía Schedule):

```
* * * * * cd /ruta/al/app && php artisan schedule:run >> /dev/null 2>&1
```

**Nunca regeneres `APP_KEY`.** Si cambia, el 2FA cifrado deja de abrir.
Las contraseñas bcrypt siguen válidas.

## Backup (servidor A, el que está vivo)

```bash
php artisan portability:backup
```

Sale una carpeta `…/YYYYMMDDTHHMMSSZ/` con:

- `database.sql.gz`
- `storage-public.tar.gz` (fotos, PDF e-CF)
- `storage-private.tar.gz` (certificados, exports)
- `MANIFEST.txt` (conteos y `SUM(sales.total)`)
- `SHA256SUMS`

Copia esa carpeta **fuera** del servidor (otro disco, otro proveedor, S3).

Este archivo incluye `storage/app/private` local solamente. Si `PRIVATE_FILESYSTEM_DRIVER=s3`, `portability:backup` no exporta el bucket de Spaces; respáldalo mediante versionado/replicación u otro proceso externo y verifica una restauración antes de depender de él.

## Restore de ensayo (base COPIA, no la viva)

El usuario de la app suele no poder `CREATE DATABASE`. Como DBA:

```sql
CREATE DATABASE comercios_rd_copy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON comercios_rd_copy.* TO 'usuario_app'@'%';
```

Luego:

```bash
php artisan portability:restore \
  --from=/var/backups/comerciosrd/YYYYMMDDTHHMMSSZ \
  --database=comercios_rd_copy
```

Ese comando **rechaza** `DB_DATABASE` del `.env` si ya hay negocios. Los
archivos salen a `storage/app/private/restore-staging/…`, no pisan el
storage vivo.

Compara `MANIFEST.txt` con:

```sql
SELECT COUNT(*) FROM businesses;
SELECT COUNT(*), SUM(total) FROM sales;
SELECT COUNT(*) FROM products;
```

## Cutover real (servidor B nuevo y vacío)

1. Baja el TTL de DNS el día anterior.
2. En A: `php artisan down` y un `portability:backup` final.
3. En B: código + `.env` con el **mismo APP_KEY**, `APP_URL` nuevo, DB vacía.
4. Restaura el dump sobre la DB de B (vacía). Si `.env` de B ya apunta a esa DB vacía:
   `php artisan portability:restore --from=… --database=NOMBRE --onto-empty-server`
   (falla si `businesses` tiene filas).
5. Copia `storage-public` → `storage/app/public` y `storage-private` → `storage/app/private`.
6. `php artisan storage:link && php artisan config:cache`
7. Una venta de prueba en un negocio real.
8. DNS hacia B. `php artisan up`.
9. A queda apagado hasta confirmar 24 h.

Si B ya tiene filas en `businesses`, **no** restaures encima. Arma otra DB.

## Qué no hacer

- `migrate:fresh`, seeders o restore sobre la base en uso
- Rotar `APP_KEY` “por higiene”
- Exportar CSV por negocio (pierdes FKs, caja y contabilidad)
- Cambiar de MySQL a PostgreSQL en el mismo cutover
- Activar S3 el día del traslado si prod guarda fotos en disco: o S3 desde el día 1, o rsync de `storage`

## Comandos

| Comando | Efecto |
|---------|--------|
| `php artisan portability:check` | Herramientas, APP_KEY, symlink, conteos |
| `php artisan portability:backup` | Dump + tar |
| `php artisan portability:restore --from=… --database=…_copy` | Restore a copia |
