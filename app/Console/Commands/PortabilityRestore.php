<?php

namespace App\Console\Commands;

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
        {--database= : Nombre de la base DESTINO (obligatorio, distinto al vivo)}
        {--storage-to= : Dónde extraer archivos (default restore-staging)}
        {--onto-empty-server : Permite crear una base destino nueva en un servidor vacío}
        {--target-host= : Host MySQL destino (default el del .env)}
        {--target-port= : Puerto MySQL destino}
        {--target-socket= : Socket Unix destino (prioridad sobre host/port)}
        {--target-username= : Usuario MySQL destino}';

    protected $description = 'Restaura dump+archivos en una base COPIA. Rechaza la base en uso.';

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
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $from = (string) $this->option('from');
        if ($from === '' || ! is_dir($from) || ! is_file($from.'/database.sql.gz')) {
            $this->error('Indica --from=ruta al backup (debe contener database.sql.gz).');

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

        $storageTo = (string) ($this->option('storage-to') ?: storage_path('app/private/restore-staging/'.basename($from)));
        File::ensureDirectoryExists($storageTo.'/public', 0700);
        File::ensureDirectoryExists($storageTo.'/private', 0700);

        $cnf = $this->mysqlCnf();
        try {
            $create = $operations->createDatabase($mysql, $cnf, $target);
            $create->setTimeout(60);
            $create->run();
            if (! $create->isSuccessful()) {
                $this->warn('El usuario de la app no puede CREATE DATABASE (normal).');
                $this->line('Como DBA, crea la copia vacía y dale permiso:');
                $this->line("  CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $this->line("  GRANT ALL ON `{$target}`.* TO CURRENT_USER;");

                return self::FAILURE;
            }

            $load = $operations->importDump($mysql, $cnf, $from.'/database.sql.gz', $target);
            $load->setTimeout(7200);
            $load->run();
            if (! $load->isSuccessful()) {
                $this->error('Restore SQL falló.');

                return self::FAILURE;
            }
        } finally {
            if (is_file($cnf)) {
                @unlink($cnf);
            }
        }

        if (is_file($from.'/storage-public.tar.gz')) {
            $operations->extractArchive($from.'/storage-public.tar.gz', $storageTo.'/public');
        }
        if (is_file($from.'/storage-private.tar.gz')) {
            $operations->extractArchive($from.'/storage-private.tar.gz', $storageTo.'/private');
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
        ];
    }

    protected function mysqlCnf(): string
    {
        $conn = $this->targetConnection();
        $path = tempnam(sys_get_temp_dir(), 'crdmy');
        chmod($path, 0600);
        $user = str_replace(['\\', '"'], ['\\\\', '\"'], $conn['username']);
        $pass = str_replace(['\\', '"'], ['\\\\', '\"'], $conn['password']);
        $lines = ['[client]', "user=\"{$user}\"", "password=\"{$pass}\""];
        if ($conn['socket'] !== '') {
            $lines[] = 'socket="'.str_replace('"', '\"', $conn['socket']).'"';
            $lines[] = 'host=localhost';
        } else {
            $lines[] = 'host='.$conn['host'];
            $lines[] = 'port='.$conn['port'];
        }
        file_put_contents($path, implode("\n", $lines)."\n");

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
