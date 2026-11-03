<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\User;
use App\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registro controlado de movimientos de inventario + actualización de stock cacheado.
 */
class InventoryService
{
    /**
     * Registra un movimiento y actualiza el stock del producto (transacción + lock).
     *
     * @param  array{
     *     type: InventoryMovementType|string,
     *     quantity: float|int,
     *     reason?: string|null,
     *     unit_cost?: float|null,
     *     reference?: Model|null,
     *     created_by?: string|null,
     * }  $data
     */
    public function move(Product $product, array $data): InventoryMovement
    {
        $type = $data['type'] instanceof InventoryMovementType
            ? $data['type']
            : InventoryMovementType::from((string) $data['type']);

        $quantity = abs((float) ($data['quantity'] ?? 0));
        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        $variant = $data['variant'] ?? null;
        if ($variant !== null && ! $variant instanceof ProductVariant) {
            $variant = ProductVariant::withoutBusinessScope()
                ->where('business_id', $product->business_id)
                ->whereKey($variant)
                ->first();
        }

        if ($variant && (string) $variant->product_id !== (string) $product->id) {
            throw new InvalidArgumentException('La variante no pertenece a este producto.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $data, $variant): InventoryMovement {
            /** @var Product $locked */
            $locked = Product::withoutBusinessScope()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current = $variant
                ? (float) ($variant->fresh()?->stock ?? $variant->stock ?? 0)
                : (float) $locked->stock;
            $delta = $type->stockSign() * $quantity;
            $newStock = round($current + $delta, 3);

            if ($newStock < 0) {
                throw new RuntimeException(
                    "Stock insuficiente para \"{$locked->name}\". Disponible: {$current}, se intentó restar: {$quantity}."
                );
            }

            $reference = $data['reference'] ?? null;

            $movement = new InventoryMovement([
                'product_id' => $locked->id,
                'variant_id' => $variant?->id,
                'type' => $type,
                'quantity' => $quantity,
                'stock_after' => $newStock,
                'unit_cost' => $data['unit_cost'] ?? $locked->cost,
                'reason' => $data['reason'] ?? null,
                'reference_id' => $reference?->getKey(),
                'reference_type' => $reference ? $reference::class : null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            $movement->business_id = $locked->business_id;
            $movement->save();

            if ($variant) {
                $variant->forceFill(['stock' => $newStock])->saveQuietly();
            } else {
                $locked->forceFill(['stock' => $newStock])->saveQuietly();
            }

            return $movement;
        });
    }

    /**
     * Ajuste manual desde UI (entrada o salida).
     */
    public function adjust(
        Product $product,
        string $type,
        float $quantity,
        string $reason,
        ?string $userId = null,
    ): InventoryMovement {
        if (! in_array($type, [
            InventoryMovementType::AdjustmentIn->value,
            InventoryMovementType::AdjustmentOut->value,
        ], true)) {
            throw new InvalidArgumentException('Tipo de ajuste inválido.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('El motivo del ajuste es obligatorio.');
        }

        return $this->move($product, [
            'type' => $type,
            'quantity' => $quantity,
            'reason' => $reason,
            'created_by' => $userId ?? auth()->id(),
        ]);
    }

    /**
     * Descuenta stock por venta (tipo sale).
     *
     * Si el producto usa lotes (farmacia), descuenta por FEFO y crea
     * un movimiento por cada lote afectado. Si no, hace el flujo clásico.
     *
     * @return InventoryMovement|list<InventoryMovement>
     */
    public function saleOut(
        Product $product,
        float $quantity,
        ?Model $sale = null,
        ?string $reason = null,
        ?SaleItem $saleItem = null,
        ?ProductVariant $variant = null,
    ): InventoryMovement|array {
        if ($product->usesBatches() && $variant === null) {
            $business = $product->business
                ?? Business::query()->find($product->business_id);

            $blockNearDays = $business?->nearExpiryBlockDays() ?? 0;

            $moves = $this->consumeFromBatchesFefo(
                product: $product,
                quantity: $quantity,
                sale: $sale,
                reason: $reason ?? 'Venta',
                blockNearDays: $blockNearDays,
                saleItem: $saleItem,
            );

            return $moves;
        }

        return $this->move($product, [
            'type' => InventoryMovementType::Sale,
            'quantity' => $quantity,
            'reason' => $reason ?? 'Venta',
            'reference' => $sale,
            'unit_cost' => $variant?->cost ?? $product->cost,
            'variant' => $variant,
        ]);
    }

    /**
     * FEFO: descuenta $quantity del producto tomando lotes vendibles
     * ordenados por expires_at ASC. Crea 1 movimiento tipo sale por lote,
     * ligados a la venta y con batch_id trazable.
     *
     * Si $saleItem viene, también crea SaleItemBatch (trazabilidad línea↔lote).
     *
     * @return list<InventoryMovement>
     */
    public function consumeFromBatchesFefo(
        Product $product,
        float $quantity,
        ?Model $sale = null,
        string $reason = 'Venta',
        int $blockNearDays = 0,
        ?SaleItem $saleItem = null,
    ): array {
        $needed = round((float) $quantity, 3);

        if ($needed <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }

        return DB::transaction(function () use ($product, $needed, $sale, $reason, $blockNearDays, $saleItem) {
            /** @var Product $locked */
            $locked = Product::withoutBusinessScope()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // S3a+: si el negocio tiene el flag strict_branch_stock activo
            // Y hay sesión con branch_id, sólo consideramos lotes de esa
            // sucursal + los "sin sucursal" (fondo común: batches viejos o
            // inventario central). Si el flag está off o no hay branch en
            // sesión (jobs/CLI/API), se comporta como antes.
            $strictBranch = static::shouldRestrictToBranch($locked);

            $batches = ProductBatch::withoutBusinessScope()
                ->withoutGlobalScope(BranchScope::class)
                ->where('business_id', $locked->business_id)
                ->where('product_id', $locked->id)
                ->when($strictBranch, function ($q) use ($strictBranch): void {
                    $q->where(function ($sub) use ($strictBranch): void {
                        $sub->whereNull('branch_id')
                            ->orWhere('branch_id', $strictBranch);
                    });
                })
                ->sellable($blockNearDays)
                ->fefoOrder()
                ->lockForUpdate()
                ->get();

            $available = (float) $batches->sum('quantity');
            if ($available + 1e-6 < $needed) {
                $branchNote = $strictBranch
                    ? ' en esta sucursal'
                    : '';

                throw new RuntimeException(
                    "Stock vendible insuficiente para \"{$locked->name}\"{$branchNote}. Disponible en lotes vigentes: {$available}, solicitado: {$needed}."
                );
            }

            $remaining = $needed;
            $movements = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min((float) $batch->quantity, $remaining);
                if ($take <= 0) {
                    continue;
                }

                // Descontar del lote
                $batch->quantity = round((float) $batch->quantity - $take, 3);
                $batch->saveQuietly();

                // Descontar stock cacheado del producto
                $newStock = round((float) $locked->stock - $take, 3);
                if ($newStock < 0) {
                    $newStock = 0;
                }
                $locked->forceFill(['stock' => $newStock])->saveQuietly();

                $movement = new InventoryMovement([
                    'product_id' => $locked->id,
                    'type' => InventoryMovementType::Sale,
                    'quantity' => $take,
                    'stock_after' => $newStock,
                    'unit_cost' => $batch->cost ?? $locked->cost,
                    'reason' => $reason.($batch->code ? ' · Lote '.$batch->code : ''),
                    'reference_id' => $sale?->getKey(),
                    'reference_type' => $sale ? $sale::class : null,
                    'created_by' => auth()->id(),
                ]);
                $movement->business_id = $locked->business_id;
                $movement->batch_id = $batch->id;
                $movement->save();

                // Trazabilidad línea de venta ↔ lote
                if ($saleItem && $sale) {
                    $allocation = new SaleItemBatch([
                        'sale_item_id' => $saleItem->id,
                        'sale_id' => $sale->getKey(),
                        'batch_id' => $batch->id,
                        'quantity' => $take,
                        'unit_cost' => $batch->cost ?? $locked->cost,
                        'returned_quantity' => 0,
                    ]);
                    $allocation->business_id = $locked->business_id;
                    $allocation->save();
                }

                // Libro de medicamentos controlados (DIGEMAPS)
                if ($sale instanceof \App\Models\Sale && $saleItem) {
                    app(\App\Services\ControlledDrugLedgerService::class)
                        ->recordSaleExit($sale, $saleItem, $locked, $take, $batch);
                }

                $movements[] = $movement;
                $remaining = round($remaining - $take, 3);
            }

            return $movements;
        });
    }

    /**
     * Restaura stock al anular venta (return_in / entrada).
     *
     * Preferencia:
     *  1) Si existen SaleItemBatch para esta venta+producto → devolver a cada lote
     *     por la cantidad no devuelta previamente (soporta devoluciones parciales
     *     futuras).
     *  2) Fallback legacy: buscar InventoryMovement de venta con batch_id.
     *  3) Si nada de lo anterior aplica: flujo clásico return_in sin lote.
     *
     * @return InventoryMovement|list<InventoryMovement>
     */
    public function saleReturn(
        Product $product,
        float $quantity,
        ?Model $sale = null,
        ?string $reason = null,
        ?ProductVariant $variant = null,
    ): InventoryMovement|array {
        if ($sale) {
            $allocations = SaleItemBatch::withoutBusinessScope()
                ->where('business_id', $product->business_id)
                ->where('sale_id', $sale->getKey())
                ->whereHas('saleItem', function ($q) use ($product) {
                    $q->where('product_id', $product->id);
                })
                ->get();

            if ($allocations->isNotEmpty()) {
                return $this->restoreFromAllocations(
                    $product,
                    $allocations,
                    $sale,
                    $reason ?? 'Anulación de venta',
                );
            }

            // Fallback legacy: por movements con batch_id
            $lotedMovements = InventoryMovement::withoutBusinessScope()
                ->withoutGlobalScope(BranchScope::class)
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->where('reference_type', $sale::class)
                ->where('reference_id', $sale->getKey())
                ->where('type', InventoryMovement::TYPE_SALE)
                ->whereNotNull('batch_id')
                ->get();

            if ($lotedMovements->isNotEmpty()) {
                return $this->restoreBatchesFromSaleMovements(
                    $product,
                    $lotedMovements,
                    $sale,
                    $reason ?? 'Anulación de venta',
                );
            }
        }

        return $this->move($product, [
            'type' => InventoryMovementType::ReturnIn,
            'quantity' => $quantity,
            'reason' => $reason ?? 'Anulación de venta',
            'reference' => $sale,
            'unit_cost' => $variant?->cost ?? $product->cost,
            'variant' => $variant,
        ]);
    }

    /**
     * Devuelve al lote exacto según SaleItemBatch, respetando cantidades
     * ya devueltas (soporte para devoluciones parciales futuras).
     *
     * @param  \Illuminate\Support\Collection<int,SaleItemBatch>  $allocations
     * @return list<InventoryMovement>
     */
    protected function restoreFromAllocations(
        Product $product,
        \Illuminate\Support\Collection $allocations,
        Model $sale,
        string $reason,
    ): array {
        return DB::transaction(function () use ($product, $allocations, $sale, $reason): array {
            /** @var Product $locked */
            $locked = Product::withoutBusinessScope()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $out = [];

            foreach ($allocations as $alloc) {
                $pending = $alloc->returnableQuantity();
                if ($pending <= 0) {
                    continue;
                }

                $batch = ProductBatch::withoutBusinessScope()
                    ->whereKey($alloc->batch_id)
                    ->lockForUpdate()
                    ->first();

                if (! $batch) {
                    // Lote borrado (raro por restrictOnDelete): fallback sin lote
                    $out[] = $this->move($locked, [
                        'type' => InventoryMovementType::ReturnIn,
                        'quantity' => $pending,
                        'reason' => $reason,
                        'reference' => $sale,
                        'unit_cost' => $alloc->unit_cost ?? $locked->cost,
                    ]);
                    $alloc->forceFill([
                        'returned_quantity' => (float) $alloc->returned_quantity + $pending,
                    ])->save();
                    continue;
                }

                $batch->quantity = round((float) $batch->quantity + $pending, 3);
                $batch->saveQuietly();

                $newStock = round((float) $locked->stock + $pending, 3);
                $locked->forceFill(['stock' => $newStock])->saveQuietly();

                $movement = new InventoryMovement([
                    'product_id' => $locked->id,
                    'type' => InventoryMovementType::ReturnIn,
                    'quantity' => $pending,
                    'stock_after' => $newStock,
                    'unit_cost' => $batch->cost ?? $locked->cost,
                    'reason' => $reason.($batch->code ? ' · Lote '.$batch->code : ''),
                    'reference_id' => $sale->getKey(),
                    'reference_type' => $sale::class,
                    'created_by' => auth()->id(),
                ]);
                $movement->business_id = $locked->business_id;
                $movement->batch_id = $batch->id;
                $movement->save();

                $alloc->forceFill([
                    'returned_quantity' => (float) $alloc->returned_quantity + $pending,
                ])->save();

                // Libro DIGEMAPS: devolución de cliente = entrada al inventario
                if ($sale instanceof \App\Models\Sale) {
                    app(\App\Services\ControlledDrugLedgerService::class)
                        ->recordSaleReturn($sale, $locked, $pending, $batch);
                }

                $out[] = $movement;
            }

            return $out;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int,InventoryMovement>  $saleMovements
     * @return list<InventoryMovement>
     */
    protected function restoreBatchesFromSaleMovements(
        Product $product,
        \Illuminate\Support\Collection $saleMovements,
        Model $sale,
        string $reason,
    ): array {
        return DB::transaction(function () use ($product, $saleMovements, $sale, $reason): array {
            /** @var Product $locked */
            $locked = Product::withoutBusinessScope()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $out = [];

            foreach ($saleMovements as $mv) {
                $batch = ProductBatch::withoutBusinessScope()
                    ->whereKey($mv->batch_id)
                    ->lockForUpdate()
                    ->first();

                if (! $batch) {
                    // Lote borrado: caer al flujo clásico para esta línea
                    $out[] = $this->move($locked, [
                        'type' => InventoryMovementType::ReturnIn,
                        'quantity' => (float) $mv->quantity,
                        'reason' => $reason,
                        'reference' => $sale,
                        'unit_cost' => $mv->unit_cost ?? $locked->cost,
                    ]);
                    continue;
                }

                $qty = (float) $mv->quantity;

                $batch->quantity = round((float) $batch->quantity + $qty, 3);
                $batch->saveQuietly();

                $newStock = round((float) $locked->stock + $qty, 3);
                $locked->forceFill(['stock' => $newStock])->saveQuietly();

                $movement = new InventoryMovement([
                    'product_id' => $locked->id,
                    'type' => InventoryMovementType::ReturnIn,
                    'quantity' => $qty,
                    'stock_after' => $newStock,
                    'unit_cost' => $batch->cost ?? $locked->cost,
                    'reason' => $reason.($batch->code ? ' · Lote '.$batch->code : ''),
                    'reference_id' => $sale->getKey(),
                    'reference_type' => $sale::class,
                    'created_by' => auth()->id(),
                ]);
                $movement->business_id = $locked->business_id;
                $movement->batch_id = $batch->id;
                $movement->save();

                $out[] = $movement;
            }

            return $out;
        });
    }

    /**
     * S3a+ · Decide si limitar la deducción FEFO a lotes de la sucursal
     * actual (más lotes "sin sucursal", fondo común).
     *
     * Devuelve la sucursal autorizada si el flag está activo. En flujos
     * autenticados, cualquier contexto ausente o inválido falla cerrado.
     * Las ventas de jobs/CLI deben establecer una identidad de usuario y
     * sucursal autorizada antes de descontar lotes.
     */
    protected static function shouldRestrictToBranch(Product $product): ?string
    {
        $business = Business::query()->find($product->business_id);
        if (! $business || ! $business->hasFeature('strict_branch_stock')) {
            return null;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException(
                'La venta requiere una sucursal explícita cuando strict_branch_stock está activo.'
            );
        }

        $sessionBusinessId = session(\App\Services\AuthContextResolver::SESSION_BUSINESS_ID)
            ?: (app()->bound('currentBusinessId') ? app('currentBusinessId') : null)
            ?: $user->current_business_id;

        if ((string) $sessionBusinessId !== (string) $product->business_id) {
            throw new AuthorizationException('No se pudo verificar el negocio de la venta.');
        }

        $snapshot = app(BranchAccessContext::class)
            ->resolveFor($user, (string) $product->business_id);
        $branchId = $snapshot->effectiveBranchId();

        if (! $snapshot->shouldRestrictReads() || $branchId === null) {
            throw new AuthorizationException(
                'Selecciona una sucursal antes de vender con strict_branch_stock activo.'
            );
        }

        return $branchId;
    }
}
