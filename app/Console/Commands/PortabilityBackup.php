<?php

namespace App\Console\Commands;

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

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Solo MySQL. DB_CONNECTION actual no es mysql.');

            return self::FAILURE;
        }

        $dumpBin = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
        if ($dumpBin === '') {
            $this->error('mysqldump no está instalado.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('dir') ?: config('portability.backup_dir')), '/');
        $stamp = gmdate('Ymd\THis\Z');
        $dest = $root.'/'.$stamp;
        File::ensureDirectoryExists($dest, 0700);

        $cnf = $this->mysqlCnf();
        $db = (string) config('database.connections.mysql.database');

        try {
            $sqlGz = $dest.'/database.sql.gz';
            $dump = Process::fromShellCommandline(
                escapeshellcmd($dumpBin)
                .' --defaults-extra-file='.escapeshellarg($cnf)
                .' --single-transaction --routines --triggers --set-gtid-purged=OFF --no-tablespaces '
                .escapeshellarg($db)
                .' | gzip -c > '.escapeshellarg($sqlGz)
            );
            $dump->setTimeout(3600);
            $dump->run();
            if (! $dump->isSuccessful() || ! is_file($sqlGz) || filesize($sqlGz) < 32) {
                $this->error('mysqldump falló.');
                $this->line(trim($dump->getErrorOutput()));

                return self::FAILURE;
            }

            $this->tarDir(storage_path('app/public'), $dest.'/storage-public.tar.gz');
            $this->tarDir(storage_path('app/private'), $dest.'/storage-private.tar.gz', [
                'backups',
                'restore-staging',
            ]);

            $manifest = $this->manifest($db, $sqlGz);
            file_put_contents($dest.'/MANIFEST.txt', $manifest);
            $this->checksums($dest);
        } finally {
            if (is_file($cnf)) {
                @unlink($cnf);
            }
        }

        $this->prune($root);
        $this->info('Backup listo: '.$dest);
        $this->line('APP_KEY fingerprint: '.$this->keyFingerprint());

        return self::SUCCESS;
    }

    protected function mysqlCnf(): string
    {
        $conn = config('database.connections.mysql');
        $path = tempnam(sys_get_temp_dir(), 'crdmy');
        chmod($path, 0600);
        $user = str_replace(['\\', '"'], ['\\\\', '\"'], (string) ($conn['username'] ?? ''));
        $pass = str_replace(['\\', '"'], ['\\\\', '\"'], (string) ($conn['password'] ?? ''));
        $host = (string) ($conn['host'] ?? '127.0.0.1');
        $port = (string) ($conn['port'] ?? '3306');
        file_put_contents(
            $path,
            "[client]\nuser=\"{$user}\"\npassword=\"{$pass}\"\nhost={$host}\nport={$port}\n"
        );

        return $path;
    }

    /**
     * @param  list<string>  $exclude
     */
    protected function tarDir(string $source, string $archive, array $exclude = []): void
    {
        File::ensureDirectoryExists($source);
        $args = ['tar', '-czf', $archive, '-C', $source];
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
            $lines[] = hash_file('sha256', $file->getPathname()).'  '.$file->getFilename();
        }
        file_put_contents($dest.'/SHA256SUMS', implode("\n", $lines)."\n");
    }

    protected function prune(string $root): void
    {
        $keepDays = max(1, (int) config('portability.keep_days', 14));
        $cutoff = time() - ($keepDays * 86400);
        if (! is_dir($root)) {
            return;
        }
        foreach (File::directories($root) as $dir) {
            if (filemtime($dir) < $cutoff) {
                File::deleteDirectory($dir);
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
