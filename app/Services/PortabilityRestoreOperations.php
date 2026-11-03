<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class PortabilityRestoreOperations
{
    public function mysqlBinary(): string
    {
        return trim((string) shell_exec('command -v mysql 2>/dev/null'));
    }

    public function createDatabase(string $mysql, string $cnf, string $target): Process
    {
        return new Process([
            $mysql,
            '--defaults-file='.$cnf,
            '--skip-force',
            '-e',
            'CREATE DATABASE `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);
    }

    public function dropDatabase(string $mysql, string $cnf, string $target): void
    {
        $process = new Process([
            $mysql,
            '--defaults-file='.$cnf,
            '--skip-force',
            '-e',
            'DROP DATABASE `'.$target.'`',
        ]);
        $process->setTimeout(60);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'No se pudo eliminar la copia parcial; requiere limpieza manual: '.trim($process->getErrorOutput())
            );
        }
    }

    public function importDump(string $mysql, string $cnf, string $dump, string $target): Process
    {
        $pipeline = 'gzip -dc '.escapeshellarg($dump)
            .' | '.escapeshellarg($mysql)
            .' --defaults-file='.escapeshellarg($cnf)
            .' --skip-force --binary-mode=1'
            .' '.escapeshellarg($target);

        return new Process(['bash', '-o', 'pipefail', '-c', $pipeline]);
    }

    public function verifyBackupIntegrity(string $from): void
    {
        $checksumsPath = $from.'/SHA256SUMS';
        if (is_link($from) || ! is_dir($from) || is_link($checksumsPath) || ! is_file($checksumsPath)) {
            throw new \RuntimeException('Falta SHA256SUMS en el respaldo.');
        }

        $contents = file_get_contents($checksumsPath);
        if ($contents === false) {
            throw new \RuntimeException('No se pudo leer SHA256SUMS.');
        }
        $lines = preg_split('/\r\n|\n/', $contents) ?: [];
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match('/^([a-f0-9]{64})  ([a-z0-9][a-z0-9._-]*)$/iD', $line, $matches)
                || $matches[2] === 'SHA256SUMS'
                || isset($entries[$matches[2]])) {
                throw new \RuntimeException('SHA256SUMS tiene un formato inválido.');
            }

            $entries[$matches[2]] = strtolower($matches[1]);
        }

        foreach (['database.sql.gz', 'MANIFEST.txt', 'storage-public.tar.gz', 'storage-private.tar.gz'] as $required) {
            if (! isset($entries[$required])) {
                throw new \RuntimeException("SHA256SUMS no incluye {$required}.");
            }
        }

        $files = scandir($from);
        if ($files === false) {
            throw new \RuntimeException('No se pudo leer el directorio del respaldo.');
        }
        foreach (array_diff($files, ['.', '..', 'SHA256SUMS']) as $filename) {
            if (! isset($entries[$filename])) {
                throw new \RuntimeException("Archivo sin checksum en el respaldo: {$filename}.");
            }
        }

        foreach ($entries as $filename => $expectedHash) {
            $path = $from.'/'.$filename;
            if (is_link($path) || ! is_file($path)) {
                throw new \RuntimeException("Falta el archivo esperado del respaldo: {$filename}.");
            }

            $currentHash = hash_file('sha256', $path);
            if ($currentHash === false || ! hash_equals($expectedHash, $currentHash)) {
                throw new \RuntimeException("Checksum SHA-256 inválido para {$filename}.");
            }
        }

        $this->verifySqlDump($from.'/database.sql.gz');
        $this->verifyArchive($from.'/storage-public.tar.gz');
        $this->verifyArchive($from.'/storage-private.tar.gz');
    }

    private function verifySqlDump(string $dump): void
    {
        $gzipCheck = new Process(['gzip', '-t', $dump]);
        $gzipCheck->setTimeout(3600);
        $gzipCheck->run();
        if (! $gzipCheck->isSuccessful()) {
            throw new \RuntimeException('database.sql.gz está corrupto o truncado: '.trim($gzipCheck->getErrorOutput()));
        }

        $tail = '';
        $read = new Process(['gzip', '-dc', $dump]);
        $read->setTimeout(3600);
        $read->disableOutput();
        $read->run(function (string $type, string $data) use (&$tail): void {
            if ($type === Process::OUT) {
                $tail = substr($tail.$data, -4096);
            }
        });
        if (! $read->isSuccessful()
            || ! preg_match('/(?:^|\n)-- Dump completed on [^\r\n]+\s*\z/', $tail)) {
            throw new \RuntimeException('Dump SQL incompleto: falta la marca final de mysqldump.');
        }
    }

    private function verifyArchive(string $archive): void
    {
        $gzip = new Process(['gzip', '-t', $archive]);
        $gzip->setTimeout(3600);
        $gzip->mustRun();

        $listing = new Process(['tar', '-tzf', $archive, '--quoting-style=escape']);
        $listing->setTimeout(3600);
        $listing->mustRun();
        if (trim($listing->getOutput()) === '') {
            throw new \RuntimeException('El respaldo tar no contiene un directorio raíz válido.');
        }
        foreach (explode("\n", rtrim($listing->getOutput(), "\n")) as $name) {
            if (str_starts_with($name, '/') || str_contains($name, '\\')
                || in_array('..', explode('/', $name), true)) {
                throw new \RuntimeException('Ruta insegura en archivo tar del respaldo.');
            }
        }

        $types = new Process(['tar', '-tvzf', $archive, '--numeric-owner', '--quoting-style=escape']);
        $types->setTimeout(3600);
        $types->mustRun();
        foreach (explode("\n", rtrim($types->getOutput(), "\n")) as $entry) {
            if ($entry !== '' && ! in_array($entry[0], ['-', 'd'], true)) {
                throw new \RuntimeException('El respaldo tar contiene enlaces o archivos especiales no permitidos.');
            }
        }
    }

    public function ensureDatabaseIsEmpty(string $mysql, string $cnf, string $target): void
    {
        $schema = "'".str_replace("'", "''", $target)."'";
        $query = 'SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '.$schema.')'
            .' + (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = '.$schema.')'
            .' + (SELECT COUNT(*) FROM information_schema.events WHERE event_schema = '.$schema.')'
            .' + (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '.$schema.')';
        $check = new Process([
            $mysql,
            '--defaults-file='.$cnf,
            '--skip-force',
            '--batch',
            '--skip-column-names',
            '-e',
            $query,
        ]);
        $check->setTimeout(60);
        $check->run();

        if (! $check->isSuccessful()) {
            throw new \RuntimeException(
                'No se pudo verificar si la base destino está vacía: '.trim($check->getErrorOutput())
            );
        }

        $output = trim($check->getOutput());
        if (! preg_match('/^[0-9]+$/D', $output)) {
            throw new \RuntimeException('No se pudo verificar si la base destino está vacía: respuesta MySQL inválida.');
        }
        if ($output !== '0') {
            throw new \RuntimeException(
                "Restauración rechazada: la base destino '{$target}' no está vacía."
            );
        }
    }

    public function extractArchive(string $archive, string $destination): void
    {
        File::ensureDirectoryExists($destination);
        $process = new Process([
            'tar', '-xzf', $archive, '--no-same-owner', '--no-same-permissions',
            '--delay-directory-restore', '-C', $destination,
        ]);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('No se pudo extraer un archivo del respaldo.');
        }
    }
}
