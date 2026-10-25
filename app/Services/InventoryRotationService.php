<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryRotationService
{
    /**
     * Analiza rotación por producto en un período (días).
     * Retorna colección con: product_id, name, sku, stock, cost, qty_sold, revenue, cogs, avg_daily, days_of_stock, turnover, class
     */
    public function analyze(Business $business, int $days = 30): Collection
    {
        $bid = $business->id;
        $from = Carbon::now()->subDays($days);

        $sold = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.business_id', $bid)
            ->where('sales.status', '!=', 'anulada')
            ->where('sales.sale_date', '>=', $from)
            ->select(
                'sale_items.product_id',
                DB::raw('SUM(sale_items.quantity) as qty_sold'),
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.quantity * COALESCE(sale_items.unit_cost, 0)) as cogs'),
            )
            ->groupBy('sale_items.product_id')
            ->get()
            ->keyBy('product_id');

        $products = DB::table('products')
            ->where('business_id', $bid)
            ->where('is_active', true)
            ->select('id', 'name', 'sku', 'stock', 'cost', 'min_stock')
            ->get();

        $rows = $products->map(function ($p) use ($sold, $days) {
            $s = $sold->get($p->id);
            $qty = (float) ($s->qty_sold ?? 0);
            $revenue = (float) ($s->revenue ?? 0);
            $cogs = (float) ($s->cogs ?? 0);
            $stock = (float) $p->stock;
            $cost = (float) $p->cost;

            $avgDaily = $days > 0 ? $qty / $days : 0;
            $daysOfStock = $avgDaily > 0 ? round($stock / $avgDaily, 1) : null;

            // Rotación = COGS / valor promedio de inventario (aproximado con stock actual × cost)
            $invValue = $stock * $cost;
            $turnover = $invValue > 0 ? round($cogs / $invValue, 2) : 0;

            // Clasificación
            $class = 'sin-movimiento';
            if ($qty > 0) {
                if ($daysOfStock !== null && $daysOfStock < 30) $class = 'rapida';
                elseif ($daysOfStock !== null && $daysOfStock < 90) $class = 'media';
                else $class = 'lenta';
            }

            return (object) [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => $stock,
                'cost' => $cost,
                'qty_sold' => $qty,
                'revenue' => $revenue,
                'cogs' => $cogs,
                'avg_daily' => round($avgDaily, 3),
                'days_of_stock' => $daysOfStock,
                'turnover' => $turnover,
                'inv_value' => round($invValue, 2),
                'class' => $class,
            ];
        });

        return $rows->sortByDesc('qty_sold')->values();
    }

    public function summary(Business $business, int $days = 30): array
    {
        $rows = $this->analyze($business, $days);

        return [
            'total_products' => $rows->count(),
            'rapida' => $rows->where('class', 'rapida')->count(),
            'media' => $rows->where('class', 'media')->count(),
            'lenta' => $rows->where('class', 'lenta')->count(),
            'sin_movimiento' => $rows->where('class', 'sin-movimiento')->count(),
            'total_inv_value' => round($rows->sum('inv_value'), 2),
            'total_revenue' => round($rows->sum('revenue'), 2),
            'total_cogs' => round($rows->sum('cogs'), 2),
        ];
    }
}
