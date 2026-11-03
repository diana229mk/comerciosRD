<?php

namespace Tests\Integration;

use App\Services\PortabilityRestoreOperations;
use Illuminate\Filesystem\Filesystem;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PortabilityMysqlRestoreTest extends TestCase
{
    private ?PDO $pdo = null;

    private ?string $directory = null;

    private string $target;

    private bool $created = false;

    private PortabilityRestoreOperations $operations;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PORTABILITY_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('Solo ejecutar mediante el servicio Docker phpunit-portability aislado.');
        }
        if (getenv('PORTABILITY_TEST_MYSQL_HOST') !== 'test-mysql'
            || ! getenv('PORTABILITY_TEST_MYSQL_PASSWORD')) {
            $this->fail('Se requiere el endpoint test-mysql aislado y su credencial de pruebas.');
        }

        $this->pdo = new PDO(
            'mysql:host=test-mysql;port=3306;dbname=information_schema;charset=utf8mb4',
            'root',
            getenv('PORTABILITY_TEST_MYSQL_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM schemata WHERE schema_name = 'comercios_rd_storage_test'"
        )->fetchColumn());
        $this->target = 'crd_restore_test_'.bin2hex(random_bytes(8)).'_copy';
        $this->directory = sys_get_temp_dir().'/'.$this->target;
        mkdir($this->directory, 0700);
        $password = str_replace(['\\', '"'], ['\\\\', '\"'], getenv('PORTABILITY_TEST_MYSQL_PASSWORD'));
        $contents = "[client]\nhost=test-mysql\nport=3306\nuser=root\npassword=\"{$password}\"\n";
        $this->assertSame(strlen($contents), file_put_contents($this->directory.'/mysql.cnf', $contents));
        $this->assertTrue(chmod($this->directory.'/mysql.cnf', 0600));
        $this->operations = new PortabilityRestoreOperations;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->created) {
                $this->operations->dropDatabase('mysql', $this->directory.'/mysql.cnf', $this->target);
                $this->created = false;
                $this->assertSame(0, $this->schemaCount());
            }
        } finally {
            $this->pdo = null;
            if ($this->directory !== null) {
                (new Filesystem)->deleteDirectory($this->directory);
            }
            parent::tearDown();
        }
    }

    public function test_valid_backup_is_verified_and_imported_into_a_disposable_schema(): void
    {
        $backup = $this->backup("CREATE TABLE restored (id INT PRIMARY KEY);\nINSERT INTO restored VALUES (42);\n");
        $this->operations->verifyBackupIntegrity($backup);
        $this->createTarget();
        $this->operations->ensureDatabaseIsEmpty('mysql', $this->directory.'/mysql.cnf', $this->target);
        $import = $this->import($backup.'/database.sql.gz');
        $this->assertTrue($import->isSuccessful(), $import->getErrorOutput());
        $this->assertSame(42, (int) $this->pdo->query('SELECT id FROM `'.$this->target.'`.restored')->fetchColumn());
    }

    public function test_nonempty_schema_is_rejected_without_changing_its_data(): void
    {
        $this->createTarget();
        $this->pdo->exec('CREATE TABLE `'.$this->target.'`.sentinel (id INT)');
        $this->pdo->exec('INSERT INTO `'.$this->target.'`.sentinel VALUES (99)');
        try {
            $this->operations->ensureDatabaseIsEmpty('mysql', $this->directory.'/mysql.cnf', $this->target);
            $this->fail('Debe rechazar la base no vacía.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no está vacía', $exception->getMessage());
        }
        $this->assertSame(99, (int) $this->pdo->query('SELECT id FROM `'.$this->target.'`.sentinel')->fetchColumn());
    }

    public function test_even_a_preexisting_empty_schema_cannot_be_reused(): void
    {
        $this->createTarget();
        $create = $this->operations->createDatabase('mysql', $this->directory.'/mysql.cnf', $this->target);
        $create->run();
        $this->assertFalse($create->isSuccessful());
        $this->assertSame(1, $this->schemaCount());
    }

    public function test_schema_with_a_routine_but_no_tables_is_not_considered_empty(): void
    {
        $this->createTarget();
        $this->pdo->exec('CREATE PROCEDURE `'.$this->target.'`.sentinel() SELECT 1');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no está vacía');
        $this->operations->ensureDatabaseIsEmpty('mysql', $this->directory.'/mysql.cnf', $this->target);
    }

    public function test_mysql_error_stops_import_and_the_partial_copy_can_be_removed(): void
    {
        $backup = $this->backup(
            "CREATE TABLE restored (id INT);\n"
            ."INSERT INTO missing_table VALUES (1);\n"
            ."INSERT INTO restored VALUES (42);\n"
        );
        $this->operations->verifyBackupIntegrity($backup);
        $this->createTarget();
        $import = $this->import($backup.'/database.sql.gz');
        $this->assertFalse($import->isSuccessful());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM `'.$this->target.'`.restored')->fetchColumn());
        $this->operations->dropDatabase('mysql', $this->directory.'/mysql.cnf', $this->target);
        $this->created = false;
        $this->assertSame(0, $this->schemaCount());
    }

    public function test_gzip_failure_propagates_even_if_mysql_accepts_the_decompressed_prefix(): void
    {
        $this->createTarget();
        $dump = gzencode("SELECT 1;\n");
        file_put_contents($this->directory.'/truncated.sql.gz', substr($dump, 0, -8));
        $this->assertFalse($this->import($this->directory.'/truncated.sql.gz')->isSuccessful());
    }

    private function createTarget(): void
    {
        $this->assertSame(0, $this->schemaCount());
        $create = $this->operations->createDatabase('mysql', $this->directory.'/mysql.cnf', $this->target);
        $create->setTimeout(60);
        $create->run();
        $this->created = $create->isSuccessful();
        $this->assertTrue($this->created, $create->getErrorOutput());
    }

    private function schemaCount(): int
    {
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM schemata WHERE schema_name = ?');
        $query->execute([$this->target]);

        return (int) $query->fetchColumn();
    }

    private function import(string $dump): Process
    {
        $import = $this->operations->importDump('mysql', $this->directory.'/mysql.cnf', $dump, $this->target);
        $import->setTimeout(60);
        $import->run();

        return $import;
    }

    private function backup(string $sql): string
    {
        $backup = $this->directory.'/backup';
        mkdir($backup, 0700);
        mkdir($this->directory.'/payload', 0700);
        file_put_contents($backup.'/database.sql.gz', gzencode($sql."-- Dump completed on 2026-01-01 00:00:00\n"));
        file_put_contents($backup.'/MANIFEST.txt', "db_driver=mysql\n");
        foreach (['public', 'private'] as $disk) {
            (new Process(['tar', '-czf', $backup.'/storage-'.$disk.'.tar.gz', '-C', $this->directory.'/payload', '.']))->mustRun();
        }
        $checksums = [];
        foreach (['database.sql.gz', 'MANIFEST.txt', 'storage-public.tar.gz', 'storage-private.tar.gz'] as $file) {
            $checksums[] = hash_file('sha256', $backup.'/'.$file).'  '.$file;
        }
        file_put_contents($backup.'/SHA256SUMS', implode("\n", $checksums)."\n");

        return $backup;
    }
}
