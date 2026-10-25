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
        return Process::fromShellCommandline(
            escapeshellcmd($mysql)
            .' --defaults-extra-file='.escapeshellarg($cnf)
            .' -e '.escapeshellarg(
                'CREATE DATABASE IF NOT EXISTS `'.$target.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            )
        );
    }

    public function importDump(string $mysql, string $cnf, string $dump, string $target): Process
    {
        return Process::fromShellCommandline(
            'gzip -dc '.escapeshellarg($dump)
            .' | '.escapeshellcmd($mysql)
            .' --defaults-extra-file='.escapeshellarg($cnf)
            .' '.escapeshellarg($target)
        );
    }

    public function extractArchive(string $archive, string $destination): void
    {
        File::ensureDirectoryExists($destination);
        $process = new Process(['tar', '-xzf', $archive, '-C', $destination]);
        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('No se pudo extraer un archivo del respaldo.');
        }
    }
}
