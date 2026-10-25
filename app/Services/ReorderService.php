<?php

namespace App\Services;

use App\Enums\PurchaseStatus;
use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alertas de stock mínimo y sugerencia de reposición automática.
 *
 * Reglas:
 *  - Se alerta cuando stock <= max(reorder_point, min_stock).
 *  - Consumo promedio diario = ventas de los últimos N días (default 30) / N.
 *  - Cobertura objetivo = 30 días (configurable).
 *  - Sugerencia = ceil(consumo_diario * (cobertura + lead_time)) - stock_actual.
 *  - Si el producto tiene reorder_quantity fijo, se usa ese valor mínimo.
 *  - Nunca menor a (min_stock - stock_actual) si aún está por debajo.
 */
class ReorderService
{
    /**
     * @param  array{
     *     window_days?: int,
     *     target_days?: int,
     *     category_id?: string|null,
     *     supplier_id?: string|null,
     *     controlled_only?: bool,
     * }  $options
     * @return Collection<int, array<string,mixed>>
     */
    public function suggestions(string $businessId, array $options = []): Collection
    {
        $windowDays = max(1, (int) ($options['window_days'] ?? 30));
        $targetDays = max(1, (int) ($options['target_days'] ?? 30));
        $categoryId = $options['category_id'] ?? null;
        $supplierId = $options['supplier_id'] ?? null;
        $controlledOnly = (bool) ($options['controlled_only'] ?? false);

        $since = Carbon::now()->subDays($windowDays);

        // Productos candidatos: bajo el mayor entre reorder_point y min_stock.
        $q = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where(function ($q) {
                // Agotado siempre entra, aunque no hayan puesto mínimo.
                $q->where('stock', '<=', 0)
                    ->orWhere(function ($q) {
                        $q->whereRaw('stock <= COALESCE(reorder_point, min_stock, 0)')
                            ->where(function ($q) {
                                $q->where('min_stock', '>', 0)
                                    ->orWhere('reorder_point', '>', 0);
                            });
                    });
            });

        if ($categoryId) {
            $q->where('category_id', $categoryId);
        }
        if ($supplierId) {
            $q->where('preferred_supplier_id', $supplierId);
        }
        if ($controlledOnly) {
            $q->whereNotNull('controlled_type');
        }

        $products = $q->with('preferredSupplier:id,name')->orderBy('name')->get();

        // Consumo agregado por producto en el rango.
        $consumption = SaleItem::query()
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) as qty')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $businessId)
            ->whereNull('sales.deleted_at')
            ->where('sales.sale_date', '>=', $since)
            ->whereIn('sale_items.product_id', $products->pluck('id'))
            ->groupBy('sale_items.product_id')
            ->pluck('qty', 'sale_items.product_id');

        return $products->map(function (Product $p) use ($consumption, $windowDays, $targetDays) {
            $consumed = (float) ($consumption[$p->id] ?? 0);
            $avgDaily = round($consumed / max(1, $windowDays), 3);
            $lead = (int) ($p->lead_time_days ?? 0);
            $stock = (float) $p->stock;
            $min = (float) $p->min_stock;
            $reorderPoint = (float) ($p->reorder_point ?: $p->min_stock);
            $daysCover = $avgDaily > 0 ? round($stock / $avgDaily, 1) : null;

            // Cantidad objetivo para cubrir target_days + lead_time
            $targetStock = ceil($avgDaily * ($targetDays + $lead));
            $suggested = max(0, $targetStock - $stock);

            if ($p->reorder_quantity && (float) $p->reorder_quantity > 0) {
                $suggested = max($suggested, (float) $p->reorder_quantity);
            }
            // Nunca menos que llevar a min_stock
            if ($min > 0 && $stock < $min) {
                $suggested = max($suggested, $min - $stock);
            }
            if ($suggested <= 0 && $stock <= 0) {
                $suggested = max(1.0, $min > 0 ? $min : 1.0);
            } elseif ($suggested <= 0 && $stock < $reorderPoint) {
                $suggested = max(1, $min - $stock);
            }

            $urgency = 'medium';
            if ($stock <= 0) {
                $urgency = 'critical';
            } elseif ($daysCover !== null && $daysCover <= max(1, $lead)) {
                $urgency = 'high';
            } elseif ($stock < $min * 0.5) {
                $urgency = 'high';
            }

            return [
                'product' => $p,
                'stock' => $stock,
                'min_stock' => $min,
                'reorder_point' => $reorderPoint,
                'avg_daily' => $avgDaily,
                'days_cover' => $daysCover,
                'lead_time_days' => $lead,
                'suggested_qty' => round($suggested, 3),
                'unit_cost' => (float) $p->cost,
                'line_total' => round($suggested * (float) $p->cost, 2),
                'supplier' => $p->preferredSupplier,
                'urgency' => $urgency,
            ];
        })->filter(fn ($row) => $row['suggested_qty'] > 0)->values();
    }

    /**
     * Crea una compra en borrador agrupando por proveedor a partir de sugerencias.
     *
     * @param  array<int, array{product_id: string, quantity: float, unit_cost?: float, supplier_id?: string|null}>  $lines
     * @return list<Purchase>
     */
    public function createPurchaseDraft(string $businessId, array $lines, ?string $userId = null): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Sin líneas para generar la orden.');
        }

        return DB::transaction(function () use ($businessId, $lines, $userId): array {
            // Agrupar por proveedor (o "sin proveedor" → una sola)
            $groups = [];
            foreach ($lines as $l) {
                $sid = $l['supplier_id'] ?? null;
                $groups[$sid ?? '__none__'][] = $l;
            }

            $result = [];
            foreach ($groups as $sid => $items) {
                $sid = $sid === '__none__' ? null : $sid;
                $number = 'OC-'.Carbon::now()->format('Ymd').'-'.str_pad((string) (Purchase::query()->where('business_id', $businessId)->whereDate('created_at', today())->count() + 1), 3, '0', STR_PAD_LEFT);

                $subtotal = 0.0;
                $purchase = new Purchase([
                    'supplier_id' => $sid,
                    'purchase_number' => $number,
                    'status' => PurchaseStatus::Borrador,
                    'purchase_date' => now()->toDateString(),
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'notes' => 'Generada automáticamente por sugerencia de reposición.',
                    'created_by' => $userId ?? auth()->id(),
                ]);
                $purchase->business_id = $businessId;
                $purchase->save();

                foreach ($items as $l) {
                    /** @var Product|null $prod */
                    $prod = Product::withoutBusinessScope()->find($l['product_id']);
                    if (! $prod) {
                        continue;
                    }
                    $qty = (float) $l['quantity'];
                    $cost = (float) ($l['unit_cost'] ?? $prod->cost);
                    $lineTotal = round($qty * $cost, 2);
                    $subtotal += $lineTotal;

                    $item = new PurchaseItem([
                        'purchase_id' => $purchase->id,
                        'product_id' => $prod->id,
                        'product_name' => $prod->name,
                        'unit' => $prod->unit,
                        'quantity' => $qty,
                        'unit_cost' => $cost,
                        'subtotal' => $lineTotal,
                    ]);
                    $item->business_id = $businessId;
                    $item->save();
                }

                $purchase->forceFill([
                    'subtotal' => $subtotal,
                    'total' => $subtotal,
                ])->save();

                $result[] = $purchase->fresh(['items']);
            }

            return $result;
        });
    }

    /**
     * Crea la orden y la marca recibida: el stock vuelve a subir.
     * En farmacia hay que pasar vencimiento de lote.
     *
     * @param  array<int, array{product_id: string, quantity: float, unit_cost?: float, supplier_id?: string|null}>  $lines
     * @return list<Purchase>
     */
    public function restockNow(string $businessId, array $lines, ?string $userId = null, array $lot = []): array
    {
        $orders = $this->createPurchaseDraft($businessId, $lines, $userId);
        $business = Business::query()->find($businessId);
        $svc = app(PurchaseService::class);
        $received = [];

        foreach ($orders as $purchase) {
            $purchase->load('items');
            foreach ($purchase->items as $item) {
                $fill = [];
                if (! empty($lot['expires_at'])) {
                    $fill['expires_at'] = $lot['expires_at'];
                }
                if (! empty($lot['batch_code'])) {
                    $fill['batch_code'] = $lot['batch_code'];
                }
                if ($fill !== []) {
                    $item->forceFill($fill)->save();
                }
            }

            $received[] = $business?->usesAdvancedInventory()
                ? $svc->receiveWithAvco($purchase)
                : $svc->receive($purchase);
        }

        return $received;
    }
}
