<?php

namespace App\Observers;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Jobs\IssueElectronicInvoiceJob;
use App\Jobs\PostSaleAccountingJob;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\AccountingOutboxService;
use App\Services\CashSessionService;
use App\Services\InventoryService;
use App\Services\KitchenInventoryService;
use App\Services\KitchenTicketService;
use App\Services\SaleCreditService;
use App\Services\SaleFinancialIntegrity;
use App\Services\SalesDailySummaryService;
use App\Support\EcfFeature;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Control central de lo que ocurre al crear o completar una venta:
 *
 *  1. Validar stock (updating → completada)
 *  2. Movimientos de inventario + descuento de stock (deduct_on_sale)
 *  3. Actualizar caja abierta (solo efectivo)
 *  4. CxC de fiado
 *  5. Resumen diario (sales_daily)
 *  6. Asiento y e-CF vía jobs (cola si SCALE_POST_SALE_QUEUE=true)
 *
 * Anulación: restoreSaleMovements + reverse de caja + decremento del resumen.
 */
class SaleObserver
{
    public function __construct(
        protected InventoryService $inventory,
    ) {}

    /**
     * Antes de actualizar: validar stock al intentar completar.
     */
    public function updating(Sale $sale): void
    {
        if (! $sale->isDirty('status')) {
            return;
        }

        // Solo validar cuando se está cambiando a "completada"
        if (! $this->statusIsCompleted($sale->status)) {
            return;
        }

        // Ya estaba completada → no revalidar
        if ($this->statusIsCompleted($sale->getOriginal('status'))) {
            return;
        }

        $sale->loadMissing('items');

        if ($sale->items->isEmpty()) {
            return;
        }

        $errors = $sale->validateStock();

        if ($errors === []) {
            return;
        }

        $message = "No se puede completar la venta:\n".implode("\n", $errors);

        $this->notifyStockInsufficient($message);

        // Revertir el cambio de estado
        $sale->status = $this->normalizeStatus($sale->getOriginal('status'));

        // Abortar el guardado (evita guardar a medias como "completada")
        throw new RuntimeException($message);
    }

    /**
     * Después de crear una venta.
     * Nota: en SaleService los ítems se guardan después del Sale;
     * en ese caso processCompletedSale se invoca explícitamente al final.
     */
    public function created(Sale $sale): void
    {
        if (! $this->statusIsCompleted($sale->status)) {
            return;
        }

        app(AccountingOutboxService::class)->register($sale);

        // Solo si los ítems ya están cargados (evita caja/contabilidad sin líneas)
        if ($sale->relationLoaded('items') && $sale->items->isNotEmpty()
            && ! ($sale->isCreditSale() && app(SaleFinancialIntegrity::class)->isConstructing($sale))) {
            $this->processCompletedSale($sale, skipInventory: false);
        }
    }

    /**
     * Después de actualizar una venta.
     */
    public function updated(Sale $sale): void
    {
        // Completada: inventario + contabilidad + caja
        if ($sale->wasChanged('status') && $this->statusIsCompleted($sale->status)) {
            app(AccountingOutboxService::class)->register($sale, resumeSkipped: true);
            $sale->loadMissing('items');
            $this->processCompletedSale($sale);
        }

        // Anulada: revertir efectivo de la caja abierta + resumen diario
        if ($sale->wasChanged('status') && $this->statusIsCancelled($sale->status)) {
            $this->reverseOpenCashSession($sale);
            app(SalesDailySummaryService::class)->applyCancelledSale($sale);
        }
    }

    /**
     * Todo lo que debe ocurrir cuando una venta se completa.
     * Público para que SaleService lo invoque tras guardar los ítems.
     */
    public function processCompletedSale(Sale $sale, bool $skipInventory = false): void
    {
        app(SaleFinancialIntegrity::class)->assertBusiness((string) $sale->business_id);
        $sale->getConnection()->transaction(function () use ($sale, $skipInventory): void {
            $locked = Sale::withoutBusinessScope()->where('business_id', $sale->business_id)
                ->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isCompleted()) {
                return;
            }
            if ($locked->isCreditSale()) {
                app(SaleCreditService::class)->complete(
                    $locked, fn (Sale $source) => $this->applyCompletedEffects($source, $skipInventory),
                );

                return;
            }
            $this->applyCompletedEffects($locked, $skipInventory);
        });
    }

    protected function applyCompletedEffects(Sale $sale, bool $skipInventory): void
    {
        if (! $this->statusIsCompleted($sale->status)) {
            return;
        }

        $sale->loadMissing('items');

        if (! $skipInventory) {
            // 1. Validar stock (defensa en profundidad)
            $errors = $sale->validateStock();
            if ($errors !== []) {
                $message = "No se puede completar la venta:\n".implode("\n", $errors);
                $this->notifyStockInsufficient($message);
                throw new RuntimeException($message);
            }

            // 2. Crear movimientos de inventario y descontar stock
            $this->createInventoryMovements($sale);

            $business = $sale->business
                ?? Business::query()->find($sale->business_id);
            if ($business?->isCafeteria()) {
                app(KitchenInventoryService::class)->consumeSaleRecipes($sale);
            }
        }

        // 3. Actualizar la caja abierta (solo efectivo) — síncrono: el cajero lo ve ya
        $this->updateOpenCashSession($sale);

        // 5. Resumen diario O(1) para dashboards
        app(SalesDailySummaryService::class)->applyCompletedSale($sale);

        // 6. Asiento y e-CF fuera del request (cola en prod; inline si SCALE_POST_SALE_QUEUE=false)
        PostSaleAccountingJob::dispatchFor($sale);
        if (EcfFeature::enabled()) {
            IssueElectronicInvoiceJob::dispatchFor($sale);
        }

        // 7. Cafetería: el cobro en caja manda el ticket a cocina (si no nació de una orden).
        if (! $skipInventory) {
            $business = $sale->business
                ?? Business::query()->find($sale->business_id);
            if ($business?->isCafeteria()) {
                app(KitchenTicketService::class)->fromSale($sale);
            }
        }
    }

    /**
     * @deprecated Usa IssueElectronicInvoiceJob::dispatchFor(). Se deja por si
     *             algún caller legado lo invoca; reenvía al job.
     */
    protected function maybeAutoIssueEcf(Sale $sale): void
    {
        if (EcfFeature::enabled()) {
            IssueElectronicInvoiceJob::dispatchFor($sale);
        }
    }

    /**
     * Movimientos de inventario + stock.
     * Solo productos con inventory_behavior = deduct_on_sale.
     * Idempotente: no duplica si la venta ya tiene movimientos tipo sale.
     *
     * Alias público: recordSaleMovements() para SaleService / código legacy.
     */
    public function recordSaleMovements(Sale $sale): void
    {
        $this->createInventoryMovements($sale);
    }

    protected function createInventoryMovements(Sale $sale): void
    {
        $sale->loadMissing('items');

        if ($sale->items->isEmpty()) {
            return;
        }

        $alreadyRecorded = InventoryMovement::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        foreach ($sale->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = Product::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($item->product_id)
                ->first();

            if (! $product || ! $product->shouldDeductStockOnSale()) {
                continue;
            }

            $variant = null;
            if ($item->variant_id) {
                $variant = ProductVariant::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)
                    ->whereKey($item->variant_id)
                    ->first();
            }

            try {
                $this->inventory->saleOut(
                    product: $product,
                    quantity: (float) $item->quantity,
                    sale: $sale,
                    reason: 'Venta #'.($sale->sale_number ?: $sale->number),
                    saleItem: $item,
                    variant: $variant,
                );
            } catch (\Throwable $e) {
                Log::error('SaleObserver: no se pudo descontar stock', [
                    'sale_id' => $sale->id,
                    'product_id' => $item->product_id,
                    'variant_id' => $item->variant_id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }

    /**
     * Restaura stock al anular. Solo productos que tuvieron movimiento de venta.
     */
    public function restoreSaleMovements(Sale $sale): void
    {
        $sale->loadMissing('items');

        $alreadyRestored = InventoryMovement::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', InventoryMovement::TYPE_RETURN_IN)
            ->exists();

        if ($alreadyRestored) {
            return;
        }

        $saleProductIds = InventoryMovement::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('reference_type', Sale::class)
            ->where('reference_id', $sale->id)
            ->where('type', InventoryMovement::TYPE_SALE)
            ->pluck('product_id')
            ->all();

        if ($saleProductIds === []) {
            return;
        }

        foreach ($sale->items as $item) {
            if (! $item->product_id || ! in_array($item->product_id, $saleProductIds, true)) {
                continue;
            }

            $product = Product::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($item->product_id)
                ->first();

            if (! $product || ! $product->track_stock) {
                continue;
            }

            $variant = null;
            if (! empty($item->variant_id)) {
                $variant = ProductVariant::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)
                    ->whereKey($item->variant_id)
                    ->first();
            }

            $this->inventory->saleReturn(
                product: $product,
                quantity: (float) $item->quantity,
                sale: $sale,
                reason: 'Anulación venta #'.($sale->sale_number ?: $sale->number),
                variant: $variant,
            );
        }
    }

    /**
     * Suma el total a total_sales_cash de la caja abierta (solo efectivo).
     * Público para SaleService si necesita llamarlo aparte.
     */
    public function updateOpenCashSession(Sale $sale): void
    {
        try {
            app(CashSessionService::class)->registerCashSale($sale);
        } catch (\Throwable $e) {
            Log::warning('SaleObserver: no se pudo actualizar caja abierta', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resta el total de la caja abierta al anular (solo efectivo).
     */
    public function reverseOpenCashSession(Sale $sale): void
    {
        try {
            app(CashSessionService::class)->reverseCashSale($sale);
        } catch (\Throwable $e) {
            Log::warning('SaleObserver: no se pudo revertir caja abierta', [
                'sale_id' => $sale->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notifyStockInsufficient(string $message): void
    {
        try {
            $type = null;
            try {
                $type = filament()->getTenant()?->business_type;
            } catch (\Throwable) {
                $type = null;
            }

            $title = match ($type) {
                'farmacia' => 'Sin existencia suficiente',
                default => 'Stock insuficiente',
            };

            Notification::make()
                ->title($title)
                ->body($message)
                ->danger()
                ->persistent()
                ->send();
        } catch (\Throwable) {
            // Sin contexto Filament (CLI/tinker/jobs)
        }
    }

    protected function statusIsCompleted(mixed $status): bool
    {
        if ($status instanceof SaleStatus) {
            return $status->isCompleted();
        }

        if ($status === null || $status === '') {
            return false;
        }

        return in_array((string) $status, [
            SaleStatus::Completada->value,
            SaleStatus::Completed->value,
            'completada',
            'completed',
        ], true);
    }

    protected function statusIsCancelled(mixed $status): bool
    {
        if ($status instanceof SaleStatus) {
            return $status->isCancelled();
        }

        if ($status === null || $status === '') {
            return false;
        }

        return in_array((string) $status, [
            SaleStatus::Anulada->value,
            SaleStatus::Cancelled->value,
            SaleStatus::Refunded->value,
            'anulada',
            'cancelada',
            'cancelled',
            'refunded',
        ], true);
    }

    protected function normalizeStatus(mixed $status): ?SaleStatus
    {
        if ($status instanceof SaleStatus) {
            return $status;
        }

        if ($status === null || $status === '') {
            return null;
        }

        return SaleStatus::tryFrom((string) $status);
    }

    /**
     * ¿Pago en efectivo? (enum o string legacy)
     */
    protected function isCashPayment(mixed $method): bool
    {
        $value = $method instanceof PaymentMethod
            ? $method->value
            : (string) ($method ?? '');

        $normalized = PaymentMethod::normalize($value !== '' ? $value : '');

        return in_array($normalized, [PaymentMethod::Efectivo, PaymentMethod::Cash], true);
    }
}
