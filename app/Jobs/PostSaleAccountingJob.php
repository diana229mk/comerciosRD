<?php

namespace App\Jobs;

use App\Jobs\Concerns\AppliesTenantContext;
use App\Models\Sale;
use App\Services\AccountingOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Asiento contable fuera del request del cajero.
 * La identidad durable es empresa/venta; el asiento y su confirmación son atómicos.
 */
class PostSaleAccountingJob implements ShouldQueue
{
    use AppliesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 15;

    public function __construct(
        public string $businessId,
        public string $saleId,
    ) {
        $this->onQueue((string) config('scale.queues.sales', 'sales'));
    }

    public static function dispatchFor(Sale $sale): void
    {
        $job = new self((string) $sale->business_id, (string) $sale->id);
        $outbox = app(AccountingOutboxService::class);
        $event = $outbox->register($sale);

        if (config('scale.post_sale_queue')) {
            $outbox->dispatchAfterCommit($event);

            return;
        }

        dispatch_sync($job);
    }

    public function handle(): void
    {
        $this->withTenant($this->businessId, function (): void {
            $sale = Sale::withoutBusinessScope()
                ->where('business_id', $this->businessId)
                ->whereKey($this->saleId)
                ->first();

            if (! $sale) {
                return;
            }

            app(AccountingOutboxService::class)->process($sale);
        });
    }
}
