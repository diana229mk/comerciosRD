<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\SalesDailySummaryService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class RebuildSalesDaily extends Command
{
    protected $signature = 'sales:rebuild-daily
                            {--business= : UUID o slug del negocio (omitir = todos)}
                            {--from= : Fecha desde (Y-m-d), default hace 7 días}
                            {--to= : Fecha hasta (Y-m-d), default hoy}';

    protected $description = 'Reconstruye sales_daily desde ventas completadas (backfill / corrección).';

    public function handle(SalesDailySummaryService $summaries): int
    {
        $from = now()->parse((string) ($this->option('from') ?: now()->subDays(7)->toDateString()))->startOfDay();
        $to = now()->parse((string) ($this->option('to') ?: now()->toDateString()))->endOfDay();

        $query = Business::query()->where('is_active', true)->orderBy('id');
        $filter = (string) $this->option('business');
        if ($filter !== '') {
            $query->where(function ($q) use ($filter): void {
                $q->where('id', $filter)->orWhere('slug', $filter);
            });
        }

        $rebuilt = 0;
        $query->chunkById(100, function ($businesses) use ($summaries, $from, $to, &$rebuilt): void {
            foreach ($businesses as $business) {
                $count = TenantContext::run((string) $business->id, function () use ($summaries, $business, $from, $to): int {
                    return $summaries->rebuild((string) $business->id, $from, $to);
                });
                $rebuilt += $count;
                $this->line("{$business->slug}: {$count} filas");
            }
        });

        $this->info("Listo. Filas escritas: {$rebuilt}.");

        return self::SUCCESS;
    }
}
