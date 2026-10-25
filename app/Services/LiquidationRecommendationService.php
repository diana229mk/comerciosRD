<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recomienda productos candidatos a liquidación:
 * - Sin ventas en X días
 * - Con stock > 0 y valor > umbral
 * - Sugiere descuento progresivo según antigüedad de última venta
 */
class LiquidationRecommendationService
{
    public function analyze(Business $business, int $deadDays = 90, float $minValue = 100.0): Collection
    {
        $bid = $business->id;
        $threshold = Carbon::now()->subDays($deadDays);

        // Última venta por producto
        $lastSale = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.business_id', $bid)
            ->where('sales.status', '!=', 'anulada')
            ->select('sale_items.product_id', DB::raw('MAX(sales.sale_date) as last_date'))
            ->groupBy('sale_items.product_id')
            ->pluck('last_date', 'product_id');

        $products = DB::table('products')
            ->where('business_id', $bid)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->select('id', 'name', 'sku', 'stock', 'cost', 'price', 'created_at')
            ->get();

        $out = collect();
        foreach ($products as $p) {
            $last = $lastSale->get($p->id);
            $lastDate = $last ? Carbon::parse($last) : Carbon::parse($p->created_at);
            $daysSince = (int) $lastDate->diffInDays(Carbon::now());

            if ($daysSince < $deadDays) continue;

            $invValue = (float) $p->stock * (float) $p->cost;
            if ($invValue < $minValue) continue;

            // Descuento sugerido progresivo
            $discount = match (true) {
                $daysSince >= 365 => 40,
                $daysSince >= 180 => 25,
                $daysSince >= 120 => 15,
                default => 10,
            };

            $suggestedPrice = round((float) $p->price * (1 - $discount / 100), 2);
            $marginAtDiscount = round((float) $suggestedPrice - (float) $p->cost, 2);

            $out->push((object) [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => (float) $p->stock,
                'cost' => (float) $p->cost,
                'price' => (float) $p->price,
                'inv_value' => round($invValue, 2),
                'days_since_last_sale' => $daysSince,
                'last_sale_at' => $lastDate->format('Y-m-d'),
                'suggested_discount' => $discount,
                'suggested_price' => $suggestedPrice,
                'margin_at_discount' => $marginAtDiscount,
                'action' => $marginAtDiscount >= 0
                    ? "Rebajar {$discount}% mantiene margen positivo"
                    : "Rebajar {$discount}% aún vende bajo costo — considere liquidar en combo",
            ]);
        }

        return $out->sortByDesc('inv_value')->values();
    }
}
