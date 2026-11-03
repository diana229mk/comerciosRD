<?php

namespace App\Console\Commands;

use App\Services\AccountingOutboxService;
use Illuminate\Console\Command;

class DispatchAccountingOutbox extends Command
{
    protected $signature = 'accounting:outbox-dispatch {--batch= : Eventos por lote} {--batches=1 : Número máximo de lotes}';

    protected $description = 'Publica eventos contables pendientes, incluidos publicados sin confirmación.';

    public function handle(AccountingOutboxService $outbox): int
    {
        $batch = $this->option('batch') ?? config('scale.accounting_outbox.batch_size');
        $batches = $this->option('batches');
        if (! ctype_digit((string) $batch) || (int) $batch < 1 || (int) $batch > 1000
            || ! ctype_digit((string) $batches) || (int) $batches < 1 || (int) $batches > 1000) {
            $this->error('Lotes y tamaño deben estar entre 1 y 1000.');

            return self::FAILURE;
        }

        $selected = $published = 0;
        for ($i = 0; $i < (int) $batches; $i++) {
            $result = $outbox->dispatchDue((int) $batch);
            $selected += $result['selected'];
            $published += $result['published'];
            if ($result['selected'] < (int) $batch) {
                break;
            }
        }
        $this->info("Revisados: {$selected}. Publicados: {$published}.");

        return self::SUCCESS;
    }
}
