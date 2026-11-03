<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Scopes\BranchScope;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
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
        $branchId = $this->resolveAuthorizedBranch($businessId);
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

        return DB::transaction(function () use ($businessId, $branchId, $scope, $filters, $productIds, $categoryIds, $params): StockCount {
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
            $count->branch_id = $branchId;
            $count->save();

            $productsQuery = Product::withoutBusinessScope()
                ->where('business_id', $businessId)
                ->where('is_active', true);

            $productsQuery->where(function ($query) use ($branchId): void {
                $query->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });

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
                        ->where('business_id', $count->business_id)
                        ->where('product_id', $product->id)
                        ->where('branch_id', $count->branch_id)
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
                            $item->branch_id = $count->branch_id;
                            $item->save();
                            $totalItems++;
                        }
                    } else {
                        if (! $this->canCountUnbatchedStock($product, $count->branch_id)) {
                            continue;
                        }

                        $item = new StockCountItem([
                            'stock_count_id' => $count->id,
                            'product_id' => $product->id,
                            'batch_id' => null,
                            'expected_quantity' => (float) $product->stock,
                            'unit_cost' => (float) $product->cost,
                        ]);
                        $item->business_id = $count->business_id;
                        $item->branch_id = $count->branch_id;
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
        return DB::transaction(function () use ($count, $countedByItemId): StockCount {
            $count = $this->lockAuthorizedCount($count);

            if (! $count->isEditable()) {
                throw new RuntimeException('El conteo ya fue cerrado.');
            }

            foreach ($countedByItemId as $itemId => $counted) {
                $item = StockCountItem::withoutBusinessScope()
                    ->where('stock_count_id', $count->id)
                    ->where('business_id', $count->business_id)
                    ->where('branch_id', $count->branch_id)
                    ->whereKey((string) $itemId)
                    ->lockForUpdate()
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

            return $count->fresh(['items']);
        });
    }

    /**
     * Cierra el conteo: aplica ajustes automáticos por diferencia,
     * ajusta stock por lote y producto, y consolida totales.
     */
    public function complete(StockCount $count, ?string $userId = null): StockCount
    {
        return DB::transaction(function () use ($count, $userId): StockCount {
            $count = $this->lockAuthorizedCount($count);

            if (! $count->isEditable()) {
                throw new RuntimeException('Solo se pueden completar conteos en borrador o en captura.');
            }

            $items = StockCountItem::withoutBusinessScope()
                ->where('stock_count_id', $count->id)
                ->where('business_id', $count->business_id)
                ->where('branch_id', $count->branch_id)
                ->whereNotNull('counted_quantity')
                ->orderBy('product_id')
                ->orderBy('batch_id')
                ->lockForUpdate()
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
                    ->where('business_id', $count->business_id)
                    ->where(function ($query) use ($count): void {
                        $query->where('branch_id', $count->branch_id)
                            ->orWhereNull('branch_id');
                    })
                    ->whereKey($item->product_id)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw new RuntimeException('El producto no pertenece al negocio y sucursal del conteo.');
                }

                if (! $item->batch_id && ! $this->canCountUnbatchedStock($product, $count->branch_id)) {
                    throw new RuntimeException('No se puede ajustar stock sin lote sin un saldo verificable para el conteo. Abra un nuevo conteo.');
                }

                $batch = null;
                if ($item->batch_id) {
                    $batch = ProductBatch::withoutBusinessScope()
                        ->where('business_id', $count->business_id)
                        ->where('branch_id', $count->branch_id)
                        ->where('product_id', $product->id)
                        ->whereKey($item->batch_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $batch) {
                        throw new RuntimeException("El lote {$item->batch_id} no existe o no pertenece a la sucursal del conteo.");
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
                $movement->branch_id = $count->branch_id;
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
        return DB::transaction(function () use ($count, $userId): StockCount {
            $count = $this->lockAuthorizedCount($count);

            if ($count->status === StockCount::STATUS_COMPLETED) {
                throw new RuntimeException('No se puede cancelar un conteo ya aplicado.');
            }
            $count->forceFill([
                'status' => StockCount::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'closed_by' => $userId ?? auth()->id(),
            ])->save();

            return $count;
        });
    }

    protected function generateCode(string $businessId): string
    {
        $prefix = 'CC-'.Carbon::now()->format('Ymd');
        $last = StockCount::withoutBusinessScope()
            ->withoutGlobalScope(BranchScope::class)
            ->where('business_id', $businessId)
            ->where('code', 'like', "$prefix-%")
            ->orderByDesc('code')
            ->value('code');
        $seq = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function canCountUnbatchedStock(Product $product, string $branchId): bool
    {
        return (string) $product->branch_id === $branchId
            && ! ProductBatch::withoutBusinessScope()
                ->withoutGlobalScope(BranchScope::class)
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->exists();
    }

    private function resolveAuthorizedBranch(string $businessId): string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new AuthorizationException('Se requiere un usuario autenticado para operar conteos.');
        }

        abort_unless(
            (string) session(AuthContextResolver::SESSION_BUSINESS_ID) === $businessId,
            404,
        );

        $user = User::query()->whereKey($user->getKey())->where('is_active', true)->first();
        if (! $user || ! Business::query()->whereKey($businessId)->where('is_active', true)->exists()) {
            throw new AuthorizationException('El usuario y el negocio deben estar activos.');
        }

        $context = app(BranchAccessContext::class);
        $context->forget($businessId);
        $branchId = $context->resolveFor($user, $businessId)->effectiveBranchId();

        if ($branchId === null || ! Branch::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->whereKey($branchId)
            ->exists()) {
            throw new AuthorizationException('Seleccione una sucursal activa del negocio para operar conteos.');
        }

        return $branchId;
    }

    private function lockAuthorizedCount(StockCount $count): StockCount
    {
        $businessId = (string) session(AuthContextResolver::SESSION_BUSINESS_ID);
        $branchId = $this->resolveAuthorizedBranch($businessId);
        $locked = StockCount::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('branch_id', $branchId)
            ->whereKey($count->getKey())
            ->lockForUpdate()
            ->first();

        abort_unless($locked instanceof StockCount, 404);

        return $locked;
    }
}
