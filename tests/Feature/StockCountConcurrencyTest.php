<?php

namespace Tests\Feature;

use App\Enums\BusinessType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Services\AuthContextResolver;
use App\Services\StockCountService;
use App\Support\TestingDatabaseGuard;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockCountConcurrencyTest extends TestCase
{
    private array $createdTables = [];

    private string $tablePrefix = '';

    private string $originalPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('test-mysql', config('database.connections.mysql.host'));
        $this->assertSame('', config('database.connections.mysql.unix_socket'));
        $this->originalPrefix = (string) config('database.connections.mysql.prefix');
        $this->assertSame('', $this->originalPrefix);
        TestingDatabaseGuard::assertIsolated($this->app);

        // Las sesiones necesitan fixtures confirmados: solo se escriben copias vacías con prefijo propio.
        $this->tablePrefix = 'crd_count_test_'.bin2hex(random_bytes(8)).'_';
        foreach ([
            'users', 'businesses', 'user_business', 'branches', 'products',
            'product_batches', 'stock_counts', 'stock_count_items', 'inventory_movements',
        ] as $source) {
            $table = $this->tablePrefix.$source;
            DB::statement("CREATE TABLE `{$table}` LIKE `{$source}`");
            $this->createdTables[] = $table;
        }
        config(['database.connections.mysql.prefix' => $this->tablePrefix]);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->createdTables !== []) {
                config(['database.connections.mysql.prefix' => $this->originalPrefix]);
                DB::purge('mysql');
                TestingDatabaseGuard::assertIsolated($this->app);
                foreach (array_reverse($this->createdTables) as $table) {
                    DB::statement("DROP TABLE `{$table}`");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    #[DataProvider('competingOperations')]
    public function test_competing_operation_waits_for_completion_and_rejects_stale_state(string $operation): void
    {
        $user = User::factory()->create();
        $business = Business::withoutEvents(fn () => Business::create([
            'name' => 'Conteo concurrente', 'slug' => 'count-concurrency', 'type' => BusinessType::Cafeteria,
            'owner_id' => $user->id, 'is_active' => true,
            'features' => ['strict_branch_scope' => true, 'strict_branch_stock' => true],
        ]));
        $user->businesses()->attach($business->id, ['role' => 'owner', 'is_active' => true]);
        $main = Branch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'name' => 'Principal', 'is_main' => true, 'is_active' => true,
        ]);
        $other = Branch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'name' => 'Otra sucursal', 'is_main' => false, 'is_active' => true,
        ]);
        $this->actingAs($user);
        session([
            AuthContextResolver::SESSION_BUSINESS_ID => (string) $business->id,
            AuthContextResolver::SESSION_BRANCH_ID => (string) $main->id,
        ]);
        app()->instance('currentBusinessId', $business->id);

        $product = Product::withoutEvents(fn () => Product::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'branch_id' => null,
            'name' => 'Producto compartido', 'sku' => 'CONCURRENT-COUNT',
            'price' => 20, 'cost' => 10, 'stock' => 15, 'min_stock' => 0, 'is_active' => true,
        ]));
        $mainBatch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'branch_id' => $main->id, 'product_id' => $product->id,
            'code' => 'MAIN', 'quantity' => 10, 'initial_quantity' => 10, 'cost' => 10, 'is_blocked' => false,
        ]);
        $otherBatch = ProductBatch::withoutBusinessScope()->forceCreate([
            'business_id' => $business->id, 'branch_id' => $other->id, 'product_id' => $product->id,
            'code' => 'OTHER', 'quantity' => 5, 'initial_quantity' => 5, 'cost' => 10, 'is_blocked' => false,
        ]);
        $service = app(StockCountService::class);
        $count = $service->open((string) $business->id);
        $item = $count->items->sole();
        $count = $service->saveCount($count, [$item->id => 12]);
        $input = new InputStream;
        $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/stock-count-concurrent-worker.php')], base_path(), [
            'STOCK_COUNT_TEST_PREFIX' => $this->tablePrefix,
            'STOCK_COUNT_TEST_USER' => (string) $user->id,
            'STOCK_COUNT_TEST_BUSINESS' => (string) $business->id,
            'STOCK_COUNT_TEST_BRANCH' => (string) $main->id,
            'STOCK_COUNT_TEST_COUNT' => (string) $count->id,
            'STOCK_COUNT_TEST_OPERATION' => $operation,
        ], $input, 20);

        try {
            $worker->start();
            $this->awaitWorker($worker, 'READY:');
            preg_match('/READY:(\d+)/', $worker->getOutput(), $connection);
            $this->assertNotSame((int) $connection[1], (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id);

            DB::beginTransaction();
            $service->complete($count);
            $input->write("start\n");
            $this->awaitWorker($worker, 'BLOCKED');
            DB::commit();
            $input->write("retry\n");
            $input->close();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertStringContainsString('REJECTED', $worker->getOutput());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $input->close();
            $worker->stop(1);
        }

        $this->assertSame('completed', $count->fresh()->status);
        $this->assertSame(12.0, (float) $count->items()->sole()->counted_quantity);
        $this->assertSame(17.0, (float) $product->fresh()->stock);
        $this->assertSame(12.0, (float) $mainBatch->fresh()->quantity);
        $this->assertSame(5.0, (float) $otherBatch->fresh()->quantity);
        $movement = DB::table('inventory_movements')->where('reference_id', $count->id)->sole();
        $this->assertSame((string) $business->id, (string) $movement->business_id);
        $this->assertSame((string) $main->id, (string) $movement->branch_id);
        $this->assertSame((string) $mainBatch->id, (string) $movement->batch_id);
        $this->assertSame(2.0, (float) $movement->quantity);
    }

    public static function competingOperations(): array
    {
        return [['complete'], ['saveCount'], ['cancel']];
    }

    private function awaitWorker(Process $worker, string $marker): void
    {
        $output = '';
        $this->assertTrue($worker->waitUntil(function (string $type, string $buffer) use (&$output, $marker): bool {
            $output .= $buffer;

            return str_contains($output, $marker);
        }), $worker->getErrorOutput().$worker->getOutput());
    }
}
