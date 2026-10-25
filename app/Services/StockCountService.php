<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockCount;
use App\Models\StockCountItem;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Servicio de conteo físico cíclico de inventario.
 *
 * Flujo:
 *  1. open(): crea el conteo, snapshotea stock esperado por lote (o por producto sin lote).
 *  2. saveCount(): guarda cantidades contadas parciales.
 *  3. complete(): genera ajustes automáticos por diferencia (adjustment_in / adjustment_out),
 *     ajusta ProductBatch.quantity y Product.stock, y consolida totales.
 *  4. cancel(): cancela un conteo sin aplicar ajustes.
 */
class StockCountService
{
    /**
     * Abre un nuevo conteo tomando snapshot del stock actual.
     *
     * @param  array{scope?: string, scope_filters?: array<string,mixed>, notes?: ?string, code?: ?string, product_ids?: array<int,string>}  $params
     */
    public function open(string $businessId, array $params = []): StockCount
    {
        $scope = $params['scope'] ?? StockCount::SCOPE_FULL;
        $filters = $params['scope_filters'] ?? [];
        $productIds = $params['product_ids'] ?? ($filters['product_ids'] ?? []);
        $categoryIds = $filters['category_ids'] ?? [];

        if (! in_array($scope, [
            StockCount::SCOPE_FULL,
            StockCount::SCOPE_CATEGORY,
            StockCount::SCOPE_SELECTION,
        ], true)) {
            throw new InvalidArgumentException('Alcance de conteo inválido.');
        }

        return DB::transaction(function () use ($businessId, $scope, $filters, $productIds, $categoryIds, $params): StockCount {
            $count = new StockCount([
                'code' => $params['code'] ?? $this->generateCode($businessId),
                'status' => StockCount::STATUS_DRAFT,
                'scope' => $scope,
                'scope_filters' => $filters ?: null,
                'expected_at' => now(),
                'started_by' => auth()->id(),
                'notes' => $params['notes'] ?? null,
            ]);
            $count->business_id = $businessId;
            $count->save();

            $productsQuery = Product::withoutBusinessScope()
                ->where('business_id', $businessId)
                ->where('is_active', true);

            if ($scope === StockCount::SCOPE_CATEGORY && $categoryIds) {
                $productsQuery->whereIn('category_id', $categoryIds);
            } elseif ($scope === StockCount::SCOPE_SELECTION && $productIds) {
                $productsQuery->whereIn('id', $productIds);
            }

            $totalItems = 0;
            $productsQuery->chunkById(200, function ($products) use ($count, &$totalItems) {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $batches = ProductBatch::withoutBusinessScope()
                        ->where('product_id', $product->id)
                        ->whereNull('deleted_at')
                        ->get();

                    if ($batches->isNotEmpty()) {
                        foreach ($batches as $batch) {
                            $item = new StockCountItem([
                                'stock_count_id' => $count->id,
                                'product_id' => $product->id,
                                'batch_id' => $batch->id,
                                'expected_quantity' => (float) $batch->quantity,
                                'unit_cost' => (float) ($batch->cost ?: $product->cost),
                            ]);
                            $item->business_id = $count->business_id;
                            $item->save();
                            $totalItems++;
                        }
                    } else {
                        $item = new StockCountItem([
                            'stock_count_id' => $count->id,
                            'product_id' => $product->id,
                            'batch_id' => null,
                            'expected_quantity' => (float) $product->stock,
                            'unit_cost' => (float) $product->cost,
                        ]);
                        $item->business_id = $count->business_id;
                        $item->save();
                        $totalItems++;
                    }
                }
            });

            $count->forceFill(['total_items' => $totalItems, 'status' => StockCount::STATUS_IN_PROGRESS])->save();

            return $count->fresh(['items']);
        });
    }

    /**
     * Guarda cantidades contadas parciales.
     *
     * @param  array<string, float|int|null>  $countedByItemId
     */
    public function saveCount(StockCount $count, array $countedByItemId): StockCount
    {
        if (! $count->isEditable()) {
            throw new RuntimeException('El conteo ya fue cerrado.');
        }

        DB::transaction(function () use ($count, $countedByItemId): void {
            foreach ($countedByItemId as $itemId => $counted) {
                $item = StockCountItem::withoutBusinessScope()
                    ->where('stock_count_id', $count->id)
                    ->whereKey((string) $itemId)
                    ->first();
                if (! $item) {
                    continue;
                }

                $countedVal = $counted === null || $counted === '' ? null : round((float) $counted, 3);
                $diff = $countedVal === null ? 0 : round($countedVal - (float) $item->expected_quantity, 3);
                $item->forceFill([
                    'counted_quantity' => $countedVal,
                    'difference' => $diff,
                    'cost_impact' => round($diff * (float) $item->unit_cost, 2),
                ])->save();
            }
        });

        return $count->fresh(['items']);
    }

    /**
     * Cierra el conteo: aplica ajustes automáticos por diferencia,
     * ajusta stock por lote y producto, y consolida totales.
     */
    public function complete(StockCount $count, ?string $userId = null): StockCount
    {
        if ($count->status !== StockCount::STATUS_IN_PROGRESS && $count->status !== StockCount::STATUS_DRAFT) {
            throw new RuntimeException('Solo se pueden completar conteos en borrador o en captura.');
        }

        return DB::transaction(function () use ($count, $userId): StockCount {
            $items = StockCountItem::withoutBusinessScope()
                ->where('stock_count_id', $count->id)
                ->whereNotNull('counted_quantity')
                ->get();

            $itemsWithDiff = 0;
            $totalDiffQty = 0.0;
            $totalCost = 0.0;

            foreach ($items as $item) {
                $diff = round((float) $item->counted_quantity - (float) $item->expected_quantity, 3);
                if (abs($diff) < 0.0005) {
                    continue;
                }

                $type = $diff > 0
                    ? InventoryMovementType::AdjustmentIn
                    : InventoryMovementType::AdjustmentOut;
                $qty = abs($diff);

                /** @var Product $product */
                $product = Product::withoutBusinessScope()
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $batch = null;
                if ($item->batch_id) {
                    $batch = ProductBatch::withoutBusinessScope()
                        ->whereKey($item->batch_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $batch) {
                        throw new Exception("El lote {$item->batch_id} ya no existe; no se puede ajustar.");
                    }
                    $newBatchQty = round((float) $batch->quantity + ($type->stockSign() * $qty), 3);
                    if ($newBatchQty < 0) {
                        throw new RuntimeException("Ajuste de salida excede stock del lote {$batch->code}.");
                    }
                    $batch->forceFill(['quantity' => $newBatchQty])->save();
                }

                $newStock = round((float) $product->stock + ($type->stockSign() * $qty), 3);
                if ($newStock < 0) {
                    throw new RuntimeException("Ajuste de salida excede stock de {$product->name}.");
                }

                $movement = new InventoryMovement([
                    'product_id' => $product->id,
                    'type' => $type,
                    'quantity' => $qty,
                    'stock_after' => $newStock,
                    'unit_cost' => (float) $item->unit_cost,
                    'reason' => "Conteo cíclico {$count->code}",
                    'reference_id' => $count->id,
                    'reference_type' => StockCount::class,
                    'created_by' => $userId ?? auth()->id(),
                ]);
                $movement->business_id = $count->business_id;
                if ($batch) {
                    $movement->batch_id = $batch->id;
                }
                $movement->save();

                $product->forceFill(['stock' => $newStock])->saveQuietly();

                $item->forceFill([
                    'difference' => $diff,
                    'cost_impact' => round($diff * (float) $item->unit_cost, 2),
                    'adjustment_movement_id' => $movement->id,
                ])->save();

                $itemsWithDiff++;
                $totalDiffQty += $diff;
                $totalCost += $diff * (float) $item->unit_cost;
            }

            $count->forceFill([
                'status' => StockCount::STATUS_COMPLETED,
                'counted_at' => $count->counted_at ?? now(),
                'completed_at' => now(),
                'closed_by' => $userId ?? auth()->id(),
                'items_with_difference' => $itemsWithDiff,
                'total_difference_qty' => round($totalDiffQty, 3),
                'total_cost_impact' => round($totalCost, 2),
            ])->save();

            return $count->fresh(['items']);
        });
    }

    public function cancel(StockCount $count, ?string $userId = null): StockCount
    {
        if ($count->status === StockCount::STATUS_COMPLETED) {
            throw new RuntimeException('No se puede cancelar un conteo ya aplicado.');
        }
        $count->forceFill([
            'status' => StockCount::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'closed_by' => $userId ?? auth()->id(),
        ])->save();

        return $count;
    }

    protected function generateCode(string $businessId): string
    {
        $prefix = 'CC-'.Carbon::now()->format('Ymd');
        $last = StockCount::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('code', 'like', "$prefix-%")
            ->orderByDesc('code')
            ->value('code');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
