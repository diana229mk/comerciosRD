<?php

namespace App\Jobs;

use App\Jobs\Concerns\AppliesTenantContext;
use App\Models\Business;
use App\Models\Sale;
use App\Services\EcfService;
use App\Support\EcfFeature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * e-CF automático fuera del request del cajero.
 * Nunca debe tumbar la venta; errores de config se loguean y el job termina OK.
 */
class IssueElectronicInvoiceJob implements ShouldQueue
{
    use AppliesTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public string $businessId,
        public string $saleId,
    ) {
        $this->onQueue((string) config('scale.queues.sales', 'sales'));
    }

    public static function dispatchFor(Sale $sale): void
    {
        if (! EcfFeature::enabled()) {
            return;
        }

        $job = new self((string) $sale->business_id, (string) $sale->id);

        if (config('scale.post_sale_queue')) {
            $pending = dispatch($job);
            if (! app()->runningUnitTests()) {
                $pending->afterCommit();
            }

            return;
        }

        dispatch_sync($job);
    }

    public function handle(): void
    {
        if (! EcfFeature::enabled()) {
            return;
        }

        $this->withTenant($this->businessId, function (): void {
            $sale = Sale::withoutBusinessScope()
                ->where('business_id', $this->businessId)
                ->whereKey($this->saleId)
                ->first();

            if (! $sale) {
                return;
            }

            try {
                $business = $sale->business
                    ?? Business::query()->find($sale->business_id);

                if (! $business || ! $business->hasModule('electronic_invoicing')) {
                    return;
                }

                $settings = EcfService::settingsFor($business);
                if (! $settings->auto_issue_on_sale) {
                    return;
                }

                EcfService::createFromSale($sale, EcfService::defaultTypeFor($business));
            } catch (Throwable $e) {
                Log::warning('IssueElectronicInvoiceJob: e-CF no emitido', [
                    'sale_id' => $this->saleId,
                    'business_id' => $this->businessId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
