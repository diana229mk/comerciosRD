<?php

use App\Models\JournalEntry;
use App\Services\AccountingOutboxService;
use App\Support\TenantContext;
use App\Support\TestingDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $prefix = getenv('QUEUE_TEST_PREFIX');
    $mode = getenv('QUEUE_TEST_MODE');
    if (getenv('APP_ENV') !== 'testing' || getenv('ACCOUNTING_QUEUE_INTEGRATION') !== '1'
        || ! is_string($prefix) || preg_match('/^crd_accounting_test_[a-f0-9]{16}_$/D', $prefix) !== 1
        || ! in_array($mode, ['once', 'drain', 'pause-before-commit', 'pause-after-commit', 'publish-once', 'pause-after-publish'], true)) {
        throw new RuntimeException('Este worker requiere el runner contable aislado y tablas nuevas propias.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    TestingDatabaseGuard::assertIsolated($app);
    if (config('database.connections.mysql.host') !== 'test-mysql'
        || config('database.connections.mysql.unix_socket') !== ''
        || config('database.redis.default.host') !== 'test-redis'
        || filled(config('database.redis.default.url'))
        || (string) config('database.redis.default.database') !== '15'
        || (string) config('database.redis.default.port') !== '6379') {
        throw new RuntimeException('Solo se permiten MySQL y Redis del entorno aislado.');
    }
    config([
        'database.connections.mysql.prefix' => $prefix,
        'database.redis.options.prefix' => $prefix,
    ]);
    DB::purge('mysql');
    $app['redis']->purge('default');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    $now = getenv('QUEUE_TEST_NOW');
    if (! is_string($now) || ! ctype_digit($now)) {
        throw new RuntimeException('Falta el reloj controlado de la prueba.');
    }
    Carbon::setTestNow(Carbon::createFromTimestamp((int) $now));
    fwrite(STDOUT, 'CONNECTION:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n");
    fflush(STDOUT);

    if (in_array($mode, ['publish-once', 'pause-after-publish'], true)) {
        if ($mode === 'pause-after-publish') {
            $app['events']->listen(JobQueued::class, function (): void {
                fwrite(STDOUT, "OUTBOX_PUBLISHED\n");
                fflush(STDOUT);
                if (trim((string) fgets(STDIN)) !== 'continue') {
                    throw new RuntimeException('Falta señal de continuación del publicador.');
                }
            });
        }
        $result = $app->make(AccountingOutboxService::class)->dispatchDue();
        fwrite(STDOUT, 'PUBLISHED:'.$result['published']."\n");
        exit(0);
    }

    if (str_starts_with($mode, 'pause-')) {
        JournalEntry::created(function () use ($mode): void {
            $pause = function () use ($mode): void {
                $marker = $mode === 'pause-after-commit' ? 'ACCOUNTING_COMMITTED' : 'ACCOUNTING_UNCOMMITTED';
                fwrite(STDOUT, $marker."\n");
                fflush(STDOUT);
                if (trim((string) fgets(STDIN)) !== 'continue') {
                    throw new RuntimeException('Falta señal de continuación del worker.');
                }
            };
            if ($mode === 'pause-after-commit') {
                DB::afterCommit($pause);
            } else {
                $pause();
            }
        });
    }
    $options = [
        'connection' => 'redis',
        '--queue' => implode(',', [
            config('scale.queues.sales'), config('scale.queues.reports'), config('queue.connections.redis.queue'),
        ]),
        '--sleep' => 0, '--tries' => 3, '--timeout' => 90,
    ];
    $options[$mode === 'drain' ? '--stop-when-empty' : '--once'] = true;
    $status = $kernel->call('queue:work', $options);
    fwrite(STDOUT, $kernel->output());
    if ($app->bound(TenantContext::EXPLICIT_BUSINESS_ID) || $app->bound('currentBusinessId')) {
        throw new RuntimeException('El worker no restauró el contexto del negocio.');
    }
    exit($status ?? 0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
