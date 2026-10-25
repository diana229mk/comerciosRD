<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chequeo de preparación para cambiar de proveedor más adelante.
 * No escribe datos de negocio.
 */
class PortabilityCheck extends Command
{
    protected $signature = 'portability:check';

    protected $description = 'Verifica que un cambio de proveedor (DB + archivos + APP_KEY) sea posible sin pérdida.';

    public function handle(): int
    {
        $ok = true;
        $this->info('Portabilidad ComerciosRD');
        $this->newLine();

        $ok = $this->check('APP_KEY definida', filled(config('app.key'))) && $ok;
        $this->line('  APP_KEY fingerprint: '.$this->keyFingerprint());

        $driver = (string) config('database.default');
        $ok = $this->check('DB es mysql (dump portable)', $driver === 'mysql') && $ok;
        $this->line('  conexión='.$driver.' host='.(config('database.connections.mysql.host') ?: 'n/a'));

        $dump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
        $ok = $this->check('mysqldump en PATH', $dump !== '') && $ok;

        $mysql = trim((string) shell_exec('command -v mysql 2>/dev/null'));
        $ok = $this->check('mysql client en PATH', $mysql !== '') && $ok;

        $publicRoot = storage_path('app/public');
        $privateRoot = storage_path('app/private');
        $ok = $this->check('storage/app/public existe', is_dir($publicRoot)) && $ok;
        $ok = $this->check('storage/app/private existe', is_dir($privateRoot)) && $ok;
        $ok = $this->check('public/storage es symlink', is_link(public_path('storage'))) && $ok;

        $disk = (string) config('filesystems.default');
        $this->line('  FILESYSTEM_DISK='.$disk);
        if ($disk === 's3') {
            $this->warn('  S3 activo: el cutover debe copiar el bucket además del dump.');
        }

        $privateDisk = (string) config('filesystems.disks.private.driver', 'local');
        $this->line('  PRIVATE_FILESYSTEM_DRIVER='.$privateDisk);
        if ($privateDisk === 's3') {
            $this->warn('  El backup local portability:backup no incluye el bucket del disco privado; configura y prueba respaldos/versionado externos de Spaces.');
        }

        $backupDir = (string) config('portability.backup_dir');
        $enabled = (bool) config('portability.backup_enabled');
        $this->line('  PORTABILITY_BACKUP_ENABLED='.($enabled ? 'true' : 'false'));
        $this->line('  PORTABILITY_BACKUP_DIR='.$backupDir);
        if (app()->environment('production') && ! $enabled) {
            $this->warn('  En producción el backup diario está apagado. Activa PORTABILITY_BACKUP_ENABLED=true.');
            $ok = false;
        }

        if ($driver === 'mysql') {
            try {
                $counts = [
                    'businesses' => $this->safeCount('businesses'),
                    'users' => $this->safeCount('users'),
                    'products' => $this->safeCount('products'),
                    'sales' => $this->safeCount('sales'),
                ];
                $this->line('  conteos: '.json_encode($counts));
                if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'total')) {
                    $sum = DB::table('sales')->sum('total');
                    $this->line('  SUM(sales.total)='.$sum);
                }
            } catch (\Throwable $e) {
                $this->warn('  No se pudieron leer conteos: '.$e->getMessage());
            }
        }

        $httpImages = 0;
        try {
            if (Schema::hasTable('products') && Schema::hasColumn('products', 'image')) {
                $httpImages = (int) DB::table('products')
                    ->where('image', 'like', 'http%')
                    ->count();
            }
        } catch (\Throwable) {
        }
        if ($httpImages > 0) {
            $this->warn("  {$httpImages} producto(s) tienen image con URL absoluta. Al cambiar de dominio esas fotos apuntan al host viejo.");
        } else {
            $this->info('  Fotos de producto: paths relativos (o ninguna URL http).');
        }

        $this->newLine();
        $this->line('Cutover futuro: mismo APP_KEY + dump MySQL + tar de storage/app/public y private.');
        $this->line('Restore nunca usa DB_DATABASE actual. Ver docs/cutover-proveedor.md');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function check(string $label, bool $pass): bool
    {
        $this->line(($pass ? '[ok] ' : '[NO] ').$label);

        return $pass;
    }

    protected function keyFingerprint(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            return '(vacía)';
        }

        return substr(hash('sha256', $key), 0, 12);
    }

    protected function safeCount(string $table): int|string
    {
        if (! Schema::hasTable($table)) {
            return 'n/a';
        }

        return (int) DB::table($table)->count();
    }
}
