<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Alertas de inventario ferretería:
 *  - Stock bajo: stock <= min_stock (o reorder_point)
 *  - Sobre-inventario: max_stock > 0 y stock > max_stock
 *  - Dead stock: sin ventas en los últimos N días (default 90) con stock > 0
 */
class InventoryAlertService
{
    public function lowStock(string $businessId): Collection
    {
        return Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_kit', false)
            ->get()
            ->filter(function (Product $p) {
                $th = (float) ($p->reorder_point ?? $p->min_stock ?? 0);
                return $th > 0 && (float) $p->stock <= $th;
            })->values();
    }

    public function overStock(string $businessId): Collection
    {
        return Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->whereNotNull('max_stock')
            ->where('max_stock', '>', 0)
            ->whereColumn('stock', '>', 'max_stock')
            ->get();
    }

    public function deadStock(string $businessId, int $days = 90): Collection
    {
        $since = Carbon::now()->subDays($days);

        // Productos con ventas recientes (excluir)
        $recent = SaleItem::query()
            ->where('business_id', $businessId)
            ->whereHas('sale', fn ($q) => $q->where('sale_date', '>=', $since))
            ->pluck('product_id')
            ->unique()
            ->all();

        return Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_kit', false)
            ->where('stock', '>', 0)
            ->whereNotIn('id', $recent)
            ->orderByDesc('stock')
            ->get();
    }

    public function summary(string $businessId): array
    {
        return [
            'low'  => $this->lowStock($businessId),
            'over' => $this->overStock($businessId),
            'dead' => $this->deadStock($businessId),
        ];
    }
}
