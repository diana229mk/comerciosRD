<?php

namespace App\Console\Commands;

use App\Enums\SaleStatus;
use App\Jobs\IssueElectronicInvoiceJob;
use App\Jobs\PostSaleAccountingJob;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SalesDaily;
use App\Services\SalesDailySummaryService;
use App\Support\EcfFeature;
use Illuminate\Console\Command;

/**
 * Red de seguridad: ventas completadas recientes sin asiento / sin resumen.
 * Corre en horario; no sustituye al worker, tapa huecos si la cola falló.
 */
class ReconcilePostSaleSideEffects extends Command
{
    protected $signature = 'sales:reconcile-side-effects {--hours=24 : Ventana hacia atrás}';

    protected $description = 'Reencola asientos/e-CF y completa sales_daily de ventas recientes.';

    public function handle(SalesDailySummaryService $summaries): int
    {
        $since = now()->subHours(max(1, (int) $this->option('hours')));

        $sales = Sale::withoutBusinessScope()
            ->whereIn('status', [
                SaleStatus::Completada->value,
                SaleStatus::Completed->value,
            ])
            ->where('sold_at', '>=', $since)
            ->orderBy('sold_at')
            ->limit(500)
            ->get();

        $queued = 0;
        $summarized = 0;

        foreach ($sales as $sale) {
            $hasJournal = JournalEntry::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->where('reference', 'Venta #'.($sale->sale_number ?: $sale->number ?: $sale->id))
                ->exists();

            if (! $hasJournal) {
                PostSaleAccountingJob::dispatchFor($sale);
                if (EcfFeature::enabled()) {
                    IssueElectronicInvoiceJob::dispatchFor($sale);
                }
                $queued++;
            }

            $date = ($sale->sold_at ?? $sale->sale_date)?->toDateString();
            $branchKey = (string) ($sale->branch_id ?? '');
            $hasSummary = $date !== null && SalesDaily::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->where('branch_key', $branchKey)
                ->whereDate('report_date', $date)
                ->exists();

            if (! $hasSummary) {
                $sale->loadMissing('items');
                $summaries->applyCompletedSale($sale);
                $summarized++;
            }
        }

        $this->info("Reencoladas: {$queued}. Resúmenes creados: {$summarized}. Revisadas: {$sales->count()}.");

        return self::SUCCESS;
    }
}
