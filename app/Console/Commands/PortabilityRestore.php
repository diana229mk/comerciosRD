<?php

namespace App\Console\Commands;

use App\Services\MysqlTlsPolicy;
use App\Services\PortabilityRestoreOperations;
use App\Services\PortabilityRestoreTargetGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Restaura un backup de portabilidad en OTRA base.
 * Nunca escribe sobre DB_DATABASE del .env en uso.
 */
class PortabilityRestore extends Command
{
    protected $signature = 'portability:restore
        {--from= : Carpeta del backup (…/YYYYMMDDTHHMMSSZ)}
        {--database= : Base DESTINO nueva e inexistente (obligatorio, distinta a la activa)}
        {--storage-to= : Dónde extraer archivos (default restore-staging)}
        {--onto-empty-server : Permite crear una base destino nueva en un servidor vacío}
        {--target-host= : Host MySQL destino (default el del .env)}
        {--target-port= : Puerto MySQL destino}
        {--target-socket= : Socket Unix destino (prioridad sobre host/port)}
        {--target-username= : Usuario MySQL destino}';

    protected $description = 'Restaura dump+archivos en una base COPIA nueva. Rechaza bases existentes y la base en uso.';

    public function handle(
        PortabilityRestoreTargetGuard $targetGuard,
        PortabilityRestoreOperations $operations,
    ): int {
        $target = (string) $this->option('database');
        $live = (string) config('database.connections.mysql.database');

        if ($target === '') {
            $this->error('Indica --database=nombre_copia (ejemplo: comercios_rd_copy).');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_]+$/', $target)) {
            $this->error('Nombre de base inválido.');

            return self::FAILURE;
        }

        $ontoEmpty = (bool) $this->option('onto-empty-server');
        $isCopyName = str_ends_with($target, '_copy');

        if (! $isCopyName && ! $ontoEmpty) {
            $this->error('El destino debe terminar en _copy, o pasa --onto-empty-server en un servidor NUEVO vacío.');

            return self::FAILURE;
        }

        try {
            $targetGuard->assertDestinationIsNotActive($target, $this->targetConnection());
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $from = (string) $this->option('from');
        if ($from === '' || ! is_dir($from) || ! is_file($from.'/database.sql.gz')) {
            $this->error('Indica --from=ruta al backup (debe contener database.sql.gz).');

            return self::FAILURE;
        }

        try {
            $operations->verifyBackupIntegrity($from);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $mysql = $operations->mysqlBinary();
        if ($mysql === '') {
            $this->error('cliente mysql no está instalado.');

            return self::FAILURE;
        }

        $manifest = is_file($from.'/MANIFEST.txt') ? file_get_contents($from.'/MANIFEST.txt') : '';
        $expectedFp = $this->manifestValue((string) $manifest, 'app_key_fingerprint');
        $currentFp = substr(hash('sha256', (string) config('app.key')), 0, 12);
        if ($expectedFp !== '' && $expectedFp !== $currentFp) {
            $this->warn('APP_KEY fingerprint del backup ('.$expectedFp.') ≠ actual ('.$currentFp.'). El 2FA cifrado no va a coincidir. Las contraseñas bcrypt sí.');
        }

        $storageTo = rtrim((string) ($this->option('storage-to') ?: storage_path('app/private/restore-staging/'.basename($from))), '/');
        $cnf = null;
        $created = false;
        $storageCreated = false;
        $createAttempted = false;
        $cleanupFailed = false;
        try {
            $cnf = $this->mysqlCnf($mysql);
            $operations->ensureDatabaseIsEmpty($mysql, $cnf, $target);

            if (file_exists($storageTo) || is_link($storageTo)) {
                throw new \RuntimeException('El destino de archivos ya existe; se requiere una carpeta nueva.');
            }
            File::ensureDirectoryExists(dirname($storageTo), 0700);
            $storageTo = realpath(dirname($storageTo)).'/'.basename($storageTo);
            foreach (['public', 'private'] as $disk) {
                $activeStorage = realpath(storage_path('app/'.$disk));
                if ($activeStorage !== false
                    && ($storageTo === $activeStorage || str_starts_with($storageTo, $activeStorage.'/'))
                    && ! ($disk === 'private' && str_starts_with($storageTo, $activeStorage.'/restore-staging/'))) {
                    throw new \RuntimeException('El destino de archivos no puede estar dentro del storage activo.');
                }
            }
            if (! mkdir($storageTo, 0700)) {
                throw new \RuntimeException('No se pudo reservar el destino de archivos.');
            }
            $storageCreated = true;
            if (file_put_contents($storageTo.'/.restore-incomplete', $target."\n") !== strlen($target) + 1) {
                throw new \RuntimeException('No se pudo marcar el staging como incompleto.');
            }
            $operations->extractArchive($from.'/storage-public.tar.gz', $storageTo.'/public');
            $operations->extractArchive($from.'/storage-private.tar.gz', $storageTo.'/private');

            $create = $operations->createDatabase($mysql, $cnf, $target);
            $create->setTimeout(60);
            $createAttempted = true;
            $create->run();
            if (! $create->isSuccessful()) {
                throw new \RuntimeException(
                    'No se pudo crear una base NUEVA; no se reutilizan bases existentes. '
                    .'Se requieren permisos CREATE/DROP sobre el destino: '.trim($create->getErrorOutput())
                );
            }
            $created = true;

            $load = $operations->importDump($mysql, $cnf, $from.'/database.sql.gz', $target);
            $load->setTimeout(7200);
            $load->run();
            if (! $load->isSuccessful()) {
                throw new \RuntimeException('Restore SQL falló: '.trim($load->getErrorOutput()));
            }
            if (! unlink($storageTo.'/.restore-incomplete')) {
                throw new \RuntimeException('No se pudo completar la publicación de los archivos restaurados.');
            }
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            if ($created) {
                try {
                    $operations->dropDatabase($mysql, $cnf, $target);
                } catch (\Throwable $cleanupException) {
                    $this->error($cleanupException->getMessage());
                    $this->error("La copia '{$target}' puede estar parcial: NO utilizarla; requiere revisión manual.");
                }
            } elseif ($createAttempted) {
                $this->warn("No se importó SQL. Revisa el estado de '{$target}' si CREATE se interrumpió; no se elimina una base cuya propiedad no pudo confirmarse.");
            }
            if ($storageCreated && ! File::deleteDirectory($storageTo)) {
                $this->error('No se pudo limpiar el staging incompleto: '.$storageTo);
            }

            return self::FAILURE;
        } finally {
            if ($cnf !== null && is_file($cnf) && ! unlink($cnf)) {
                $this->error('No se pudo eliminar el archivo temporal de conexión MySQL: '.$cnf);
                $cleanupFailed = true;
            }
        }

        if ($cleanupFailed) {
            return self::FAILURE;
        }
        $this->info('Restore OK en base '.$target);
        $this->line('Archivos en staging (NO se pisó storage vivo): '.$storageTo);
        $this->line('La app en uso sigue apuntando a '.$live);

        return self::SUCCESS;
    }

    /**
     * @return array{host:string,port:string,socket:string,username:string,password:string}
     */
    protected function targetConnection(): array
    {
        $conn = config('database.connections.mysql');
        $pass = (string) ($conn['password'] ?? '');
        if (getenv('PORTABILITY_RESTORE_PASSWORD') !== false) {
            $pass = (string) getenv('PORTABILITY_RESTORE_PASSWORD');
        }

        return [
            'host' => (string) ($this->option('target-host') ?: ($conn['host'] ?? '127.0.0.1')),
            'port' => (string) ($this->option('target-port') ?: ($conn['port'] ?? '3306')),
            'socket' => (string) ($this->option('target-socket') ?: ($conn['unix_socket'] ?? '')),
            'username' => (string) ($this->option('target-username') ?: ($conn['username'] ?? '')),
            'password' => $pass,
            'ssl_required' => (bool) ($conn['ssl_required'] ?? false),
            'ssl_ca' => MysqlTlsPolicy::ca($conn),
        ];
    }

    protected function mysqlCnf(string $binary): string
    {
        $conn = $this->targetConnection();
        $tlsOptions = MysqlTlsPolicy::clientOptions($conn, $binary);
        $user = MysqlTlsPolicy::quote($conn['username']);
        $pass = MysqlTlsPolicy::quote($conn['password']);
        $lines = ['[client]', "user=\"{$user}\"", "password=\"{$pass}\""];
        if ($conn['socket'] !== '') {
            $lines[] = 'socket="'.MysqlTlsPolicy::quote($conn['socket']).'"';
            $lines[] = 'host=localhost';
        } else {
            $lines[] = 'host="'.MysqlTlsPolicy::quote($conn['host']).'"';
            $lines[] = 'port="'.MysqlTlsPolicy::quote($conn['port']).'"';
        }
        $contents = implode("\n", [...$lines, ...$tlsOptions])."\n";
        $path = tempnam(sys_get_temp_dir(), 'crdmy');
        if ($path === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal de conexión MySQL.');
        }
        if (! chmod($path, 0600)) {
            @unlink($path);
            throw new \RuntimeException('No se pudo proteger el archivo temporal de conexión MySQL.');
        }
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            @unlink($path);
            throw new \RuntimeException('No se pudo escribir el archivo temporal de conexión MySQL.');
        }

        return $path;
    }

    protected function manifestValue(string $manifest, string $key): string
    {
        foreach (preg_split('/\r\n|\n/', $manifest) ?: [] as $line) {
            if (str_starts_with($line, $key.'=')) {
                return substr($line, strlen($key) + 1);
            }
        }

        return '';
    }
}
