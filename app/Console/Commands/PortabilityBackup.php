<?php

namespace App\Console\Commands;

use App\Services\MysqlTlsPolicy;
use App\Services\PortabilityRestoreOperations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

/**
 * Copia de portabilidad: MySQL + archivos. No toca filas de negocio.
 */
class PortabilityBackup extends Command
{
    protected $signature = 'portability:backup {--dir= : Destino (default config portability.backup_dir)}';

    protected $description = 'Dump MySQL + tar de storage para poder cambiar de proveedor más adelante.';

    public function handle(PortabilityRestoreOperations $operations): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Solo MySQL. DB_CONNECTION actual no es mysql.');

            return self::FAILURE;
        }

        if (config('filesystems.default') === 's3'
            || config('filesystems.disks.private.driver', 'local') === 's3'
        ) {
            $this->error(
                'El backup local no incluye los buckets S3/Spaces configurados; respalda y verifica esos objetos por separado.'
            );

            return self::FAILURE;
        }

        $dumpBin = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
        if ($dumpBin === '') {
            $this->error('mysqldump no está instalado.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('dir') ?: config('portability.backup_dir')), '/');
        $stamp = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4));
        $published = $root.'/'.$stamp;
        $dest = $root.'/.'.$stamp.'.incomplete';
        $cnf = null;
        $db = (string) config('database.connections.mysql.database');
        $sqlTemp = null;
        $stagingCreated = false;
        $cleanupFailed = false;
        try {
            File::ensureDirectoryExists($root, 0700);
            if (! mkdir($dest, 0700)) {
                throw new \RuntimeException('No se pudo crear el staging del respaldo.');
            }
            $stagingCreated = true;
            $cnf = $this->mysqlCnf($dumpBin);
            $sqlTemp = tempnam(sys_get_temp_dir(), 'crdsql');
            if ($sqlTemp === false || ! chmod($sqlTemp, 0600)) {
                throw new \RuntimeException('No se pudo crear un archivo temporal seguro para el dump.');
            }
            $sqlGz = $dest.'/database.sql.gz';
            $version = new Process([$dumpBin, '--no-defaults', '--version']);
            $version->setTimeout(30);
            $version->mustRun();
            $arguments = [
                $dumpBin,
                '--defaults-file='.$cnf,
                '--single-transaction',
                '--routines',
                '--triggers',
                '--events',
                '--comments',
                '--dump-date',
                '--no-tablespaces',
                '--result-file='.$sqlTemp,
            ];
            if (! str_contains($version->getOutput(), 'MariaDB')) {
                $arguments[] = '--set-gtid-purged=OFF';
            }
            $arguments[] = $db;
            $dump = new Process($arguments);
            $dump->setTimeout(3600);
            $dump->run();
            if (! $dump->isSuccessful() || ! is_file($sqlTemp) || filesize($sqlTemp) === 0) {
                throw new \RuntimeException('mysqldump falló: '.trim($dump->getErrorOutput()));
            }

            $compress = new Process(['gzip', '-f', $sqlTemp]);
            $compress->setTimeout(3600);
            $compress->run();
            if (! $compress->isSuccessful() || ! is_file($sqlTemp.'.gz')) {
                throw new \RuntimeException('No se pudo comprimir el dump MySQL: '.trim($compress->getErrorOutput()));
            }
            if (! rename($sqlTemp.'.gz', $sqlGz)) {
                throw new \RuntimeException('No se pudo mover el dump comprimido al destino.');
            }

            $this->tarDir(storage_path('app/public'), $dest.'/storage-public.tar.gz');
            $this->tarDir(storage_path('app/private'), $dest.'/storage-private.tar.gz', [
                'backups',
                'restore-staging',
            ]);

            $manifest = $this->manifest($db, $sqlGz);
            if (file_put_contents($dest.'/MANIFEST.txt', $manifest) !== strlen($manifest)) {
                throw new \RuntimeException('No se pudo escribir MANIFEST.txt.');
            }
            $this->checksums($dest);
            $operations->verifyBackupIntegrity($dest);
            if (file_exists($published) || ! rename($dest, $published)) {
                throw new \RuntimeException('No se pudo publicar el respaldo completo.');
            }
            $stagingCreated = false;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            foreach ([$cnf, $sqlTemp, is_string($sqlTemp) ? $sqlTemp.'.gz' : null] as $temporary) {
                if (is_string($temporary) && is_file($temporary) && ! unlink($temporary)) {
                    $this->error('No se pudo eliminar un temporal privado del respaldo: '.$temporary);
                    $cleanupFailed = true;
                }
            }
            if ($stagingCreated && ! File::deleteDirectory($dest)) {
                $this->error('No se pudo limpiar el respaldo incompleto: '.$dest);
            }
        }

        if ($cleanupFailed) {
            return self::FAILURE;
        }
        $this->prune($root);
        $this->info('Backup listo: '.$published);
        $this->line('APP_KEY fingerprint: '.$this->keyFingerprint());

        return self::SUCCESS;
    }

    protected function mysqlCnf(string $binary): string
    {
        $conn = config('database.connections.mysql');
        $tlsOptions = MysqlTlsPolicy::clientOptions($conn, $binary);
        $user = MysqlTlsPolicy::quote((string) ($conn['username'] ?? ''));
        $pass = MysqlTlsPolicy::quote((string) ($conn['password'] ?? ''));
        $host = MysqlTlsPolicy::quote((string) ($conn['host'] ?? '127.0.0.1'));
        $port = MysqlTlsPolicy::quote((string) ($conn['port'] ?? '3306'));
        $contents = "[client]\nuser=\"{$user}\"\npassword=\"{$pass}\"\nhost=\"{$host}\"\nport=\"{$port}\"\n";
        if (filled($conn['unix_socket'] ?? null)) {
            $contents .= 'socket="'.MysqlTlsPolicy::quote((string) $conn['unix_socket'])."\"\nhost=localhost\n";
        }
        $contents .= $tlsOptions === [] ? '' : implode("\n", $tlsOptions)."\n";
        $path = tempnam(sys_get_temp_dir(), 'crdmy');
        if ($path === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal de conexión MySQL.');
        }
        if (! chmod($path, 0600)) {
            @unlink($path);
            throw new \RuntimeException('No se pudo proteger el archivo temporal de conexión MySQL.');
        }
        if (file_put_contents(
            $path,
            $contents,
        ) !== strlen($contents)) {
            @unlink($path);
            throw new \RuntimeException('No se pudo escribir el archivo temporal de conexión MySQL.');
        }

        return $path;
    }

    /**
     * @param  list<string>  $exclude
     */
    protected function tarDir(string $source, string $archive, array $exclude = []): void
    {
        File::ensureDirectoryExists($source);
        $args = ['tar', '--hard-dereference', '-czf', $archive, '-C', $source];
        foreach ($exclude as $name) {
            $args[] = '--exclude='.$name;
        }
        $args[] = '.';
        $p = new Process($args);
        $p->setTimeout(3600);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('tar falló: '.trim($p->getErrorOutput()));
        }
    }

    protected function manifest(string $db, string $sqlGz): string
    {
        $lines = [
            'created_at='.gmdate('c'),
            'app_env='.(string) config('app.env'),
            'db_name='.$db,
            'db_driver=mysql',
            'app_key_fingerprint='.$this->keyFingerprint(),
            'filesystem_disk='.(string) config('filesystems.default'),
            'private_filesystem_driver='.(string) config('filesystems.disks.private.driver', 'local'),
            'sql_bytes='.(string) filesize($sqlGz),
        ];

        foreach (['businesses', 'users', 'products', 'sales', 'customers'] as $table) {
            $lines[] = 'count_'.$table.'='.$this->safeCount($table);
        }
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'total')) {
            $lines[] = 'sum_sales_total='.(string) DB::table('sales')->sum('total');
        }

        return implode("\n", $lines)."\n";
    }

    protected function checksums(string $dest): void
    {
        $lines = [];
        foreach (File::files($dest) as $file) {
            if ($file->getFilename() === 'SHA256SUMS') {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new \RuntimeException('No se pudo calcular SHA-256 para '.$file->getFilename());
            }
            $lines[] = $hash.'  '.$file->getFilename();
        }
        $contents = implode("\n", $lines)."\n";
        if (file_put_contents($dest.'/SHA256SUMS', $contents) !== strlen($contents)) {
            throw new \RuntimeException('No se pudo escribir SHA256SUMS.');
        }
    }

    protected function prune(string $root): void
    {
        $keepDays = max(1, (int) config('portability.keep_days', 14));
        $cutoff = time() - ($keepDays * 86400);
        if (! is_dir($root)) {
            return;
        }
        foreach (File::directories($root) as $dir) {
            if (preg_match('/^\d{8}T\d{6}Z(?:-[a-f0-9]{8})?$/D', basename($dir))
                && ! is_link($dir) && is_file($dir.'/SHA256SUMS') && filemtime($dir) < $cutoff
                && ! File::deleteDirectory($dir)) {
                throw new \RuntimeException('No se pudo eliminar el respaldo vencido: '.$dir);
            }
        }
    }

    protected function keyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 12);
    }

    protected function safeCount(string $table): string
    {
        if (! Schema::hasTable($table)) {
            return 'n/a';
        }

        return (string) DB::table($table)->count();
    }
}
