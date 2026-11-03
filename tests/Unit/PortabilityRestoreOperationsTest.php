<?php

namespace Tests\Unit;

use App\Services\PortabilityRestoreOperations;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PortabilityRestoreOperationsTest extends TestCase
{
    private string $directory;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        // Docker mounts /tmp with noexec; simulated clients must be executable.
        $this->directory = dirname(__DIR__, 2).'/storage/framework/cache/crd-portability-unit-'.bin2hex(random_bytes(8));
        $this->backup = $this->directory.'/backup';
        mkdir($this->backup, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_verify_backup_integrity_accepts_valid_backup(): void
    {
        $this->makeBackup();
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($this->backup);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('payloadFiles')]
    public function test_verify_backup_integrity_rejects_checksum_mismatch_for_every_payload(string $filename): void
    {
        $this->makeBackup();
        file_put_contents($this->backup.'/'.$filename, 'modified', FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Checksum SHA-256 inválido para '.$filename);
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($this->backup);
    }

    public static function payloadFiles(): array
    {
        return array_map(fn ($name) => [$name], [
            'database.sql.gz', 'MANIFEST.txt', 'storage-public.tar.gz', 'storage-private.tar.gz',
        ]);
    }

    #[DataProvider('invalidDumps')]
    public function test_rejects_corrupt_truncated_or_incomplete_sql_even_with_matching_hashes(string $contents, string $message): void
    {
        $this->makeBackup();
        file_put_contents($this->backup.'/database.sql.gz', $contents);
        $this->writeChecksums();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($this->backup);
    }

    public static function invalidDumps(): array
    {
        $complete = gzencode("SELECT 1;\n-- Dump completed on 2026-01-01 00:00:00\n");

        return [
            'not gzip' => ['not-a-gzip-file', 'corrupto o truncado'],
            'truncated gzip trailer' => [substr($complete, 0, -8), 'corrupto o truncado'],
            'corrupt gzip crc' => [substr($complete, 0, -8).str_repeat("\0", 8), 'corrupto o truncado'],
            'empty gzip payload' => [gzencode(''), 'Dump SQL incompleto'],
            'SQL cut before footer' => [gzencode('CREATE TABLE unfinished ('), 'Dump SQL incompleto'],
            'SQL appended after footer' => [gzencode("SELECT 1;\n-- Dump completed on date\nCREATE TABLE broken ("), 'Dump SQL incompleto'],
        ];
    }

    #[DataProvider('invalidInventories')]
    public function test_rejects_invalid_checksum_inventory(string $modification, string $message): void
    {
        $this->makeBackup();
        $path = $this->backup.'/SHA256SUMS';
        $lines = file($path);
        switch ($modification) {
            case 'missing':
                unlink($path);
                break;
            case 'omitted':
                file_put_contents($path, implode('', array_slice($lines, 1)));
                break;
            case 'duplicate':
                file_put_contents($path, $lines[0], FILE_APPEND);
                break;
            case 'traversal':
                file_put_contents($path, str_repeat('0', 64)."  ../outside\n", FILE_APPEND);
                break;
            case 'absolute':
                file_put_contents($path, str_repeat('0', 64)."  /etc/passwd\n", FILE_APPEND);
                break;
            case 'self':
                file_put_contents($path, str_repeat('0', 64)."  SHA256SUMS\n", FILE_APPEND);
                break;
            case 'unlisted':
                file_put_contents($this->backup.'/.unexpected', 'unverified');
                break;
            case 'missing payload':
                unlink($this->backup.'/MANIFEST.txt');
                break;
            case 'symlink':
                rename($this->backup.'/MANIFEST.txt', $this->directory.'/manifest');
                symlink($this->directory.'/manifest', $this->backup.'/MANIFEST.txt');
                break;
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($this->backup);
    }

    public static function invalidInventories(): array
    {
        return [
            ['missing', 'Falta SHA256SUMS'],
            ['omitted', 'SHA256SUMS no incluye'],
            ['duplicate', 'formato inválido'],
            ['traversal', 'formato inválido'],
            ['absolute', 'formato inválido'],
            ['self', 'formato inválido'],
            ['unlisted', 'Archivo sin checksum'],
            ['missing payload', 'Falta el archivo esperado'],
            ['symlink', 'Falta el archivo esperado'],
        ];
    }

    #[DataProvider('invalidArchives')]
    public function test_rejects_invalid_archives_before_restore(string $kind): void
    {
        $this->makeBackup();
        $archive = $this->backup.'/storage-private.tar.gz';
        if ($kind === 'invalid tar') {
            file_put_contents($archive, gzencode('not a tar'));
        } elseif ($kind === 'truncated') {
            $contents = file_get_contents($archive);
            file_put_contents($archive, substr($contents, 0, -8));
        } elseif ($kind === 'traversal') {
            (new Process(['tar', '-czf', $archive, '-C', $this->directory.'/payload', '--transform=s|document|../escaped|', '.']))->mustRun();
        } else {
            symlink('/etc/passwd', $this->directory.'/payload/link');
            (new Process(['tar', '-czf', $archive, '-C', $this->directory.'/payload', '.']))->mustRun();
        }
        $this->writeChecksums();

        $this->expectException(\RuntimeException::class);
        (new PortabilityRestoreOperations)->verifyBackupIntegrity($this->backup);
    }

    public static function invalidArchives(): array
    {
        return [['invalid tar'], ['truncated'], ['traversal'], ['symlink']];
    }

    #[DataProvider('databaseResponses')]
    public function test_database_emptiness_check_fails_closed(string $response, int $exit, ?string $error): void
    {
        $mysql = $this->fakeMysql(
            "printf '%s' ".escapeshellarg($response)."\nprintf 'simulated mysql failure' >&2\nexit ".$exit."\n"
        );
        if ($error !== null) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($error);
        }
        (new PortabilityRestoreOperations)->ensureDatabaseIsEmpty($mysql, '/unused.cnf', 'disposable_copy');
        if ($error === null) {
            $this->addToAssertionCount(1);
        }
    }

    public static function databaseResponses(): array
    {
        return [
            ["0\n", 0, null],
            ["1\n", 0, 'no está vacía'],
            ['', 0, 'respuesta MySQL inválida'],
            ["unexpected\n", 0, 'respuesta MySQL inválida'],
            ["0\n", 1, 'simulated mysql failure'],
        ];
    }

    #[DataProvider('pipelineFailures')]
    public function test_import_propagates_failures_from_both_pipeline_components(bool $corruptGzip, int $mysqlExit): void
    {
        $dump = $this->directory.'/input.sql.gz';
        file_put_contents($dump, $corruptGzip ? 'broken gzip' : gzencode("SELECT 1;\n"));
        $mysql = $this->fakeMysql("cat >/dev/null\nexit ".$mysqlExit."\n");
        $process = (new PortabilityRestoreOperations)->importDump($mysql, '/unused.cnf', $dump, 'disposable_copy');
        $process->run();
        $this->assertSame(! $corruptGzip && $mysqlExit === 0, $process->isSuccessful());
        $this->assertSame($mysqlExit !== 0 ? $mysqlExit : ($corruptGzip ? 1 : 0), $process->getExitCode());
    }

    public static function pipelineFailures(): array
    {
        return [[false, 0], [true, 0], [false, 7], [true, 7]];
    }

    private function makeBackup(): void
    {
        file_put_contents($this->backup.'/database.sql.gz', gzencode("SELECT 1;\n-- Dump completed on 2026-01-01 00:00:00\n"));
        file_put_contents($this->backup.'/MANIFEST.txt', "created_at=2026-01-01T00:00:00Z\n");
        mkdir($this->directory.'/payload');
        file_put_contents($this->directory.'/payload/document', 'backup content');
        foreach (['public', 'private'] as $disk) {
            (new Process(['tar', '-czf', $this->backup.'/storage-'.$disk.'.tar.gz', '-C', $this->directory.'/payload', '.']))->mustRun();
        }
        $this->writeChecksums();
    }

    private function writeChecksums(): void
    {
        $lines = [];
        foreach (self::payloadFiles() as [$file]) {
            $lines[] = hash_file('sha256', $this->backup.'/'.$file).'  '.$file;
        }
        file_put_contents($this->backup.'/SHA256SUMS', implode("\n", $lines)."\n");
    }

    private function fakeMysql(string $body): string
    {
        $path = $this->directory.'/fake mysql';
        file_put_contents($path, "#!/bin/sh\n".$body);
        chmod($path, 0700);

        return $path;
    }
}
