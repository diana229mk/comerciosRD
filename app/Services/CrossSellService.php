<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Market basket analysis (co-occurrence de productos en ventas).
 * Sugiere cross-sell tipo "quien compra A también lleva B".
 */
class CrossSellService
{
    /**
     * Devuelve los top N pares (product_a, product_b) que más aparecen juntos.
     * Métricas: support (co-occurrencia) y confidence (P(B|A)).
     *
     * @return Collection<int, object>
     */
    public function topPairs(Business $business, int $days = 90, int $limit = 30, int $minCount = 2): Collection
    {
        $bid = $business->id;
        $from = Carbon::now()->subDays($days);

        // Frecuencia individual de cada producto
        $singleCounts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.business_id', $bid)
            ->where('sales.status', '!=', 'anulada')
            ->where('sales.sale_date', '>=', $from)
            ->select('sale_items.product_id', DB::raw('COUNT(DISTINCT sale_items.sale_id) as n'))
            ->groupBy('sale_items.product_id')
            ->pluck('n', 'product_id');

        // Pares en la misma venta (self-join)
        $pairs = DB::table('sale_items as a')
            ->join('sale_items as b', function ($j) {
                $j->on('a.sale_id', '=', 'b.sale_id')
                  ->whereColumn('a.product_id', '<', 'b.product_id');
            })
            ->join('sales', 'sales.id', '=', 'a.sale_id')
            ->where('a.business_id', $bid)
            ->where('sales.status', '!=', 'anulada')
            ->where('sales.sale_date', '>=', $from)
            ->select(
                'a.product_id as product_a',
                'b.product_id as product_b',
                DB::raw('COUNT(DISTINCT a.sale_id) as pair_count'),
            )
            ->groupBy('a.product_id', 'b.product_id')
            ->having('pair_count', '>=', $minCount)
            ->orderByDesc('pair_count')
            ->limit($limit)
            ->get();

        // Nombres
        $ids = $pairs->pluck('product_a')->merge($pairs->pluck('product_b'))->unique()->values();
        $names = DB::table('products')
            ->where('business_id', $bid)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        return $pairs->map(function ($pair) use ($names, $singleCounts) {
            $nA = (int) ($singleCounts[$pair->product_a] ?? 0);
            $nB = (int) ($singleCounts[$pair->product_b] ?? 0);
            $confAB = $nA > 0 ? round(($pair->pair_count / $nA) * 100, 1) : 0;
            $confBA = $nB > 0 ? round(($pair->pair_count / $nB) * 100, 1) : 0;

            return (object) [
                'product_a' => $pair->product_a,
                'name_a' => $names[$pair->product_a] ?? '?',
                'product_b' => $pair->product_b,
                'name_b' => $names[$pair->product_b] ?? '?',
                'count' => (int) $pair->pair_count,
                'conf_a_to_b' => $confAB,
                'conf_b_to_a' => $confBA,
            ];
        });
    }

    /**
     * Dado un producto, retorna los N productos que más se llevan junto con él.
     */
    public function recommendationsFor(Business $business, string $productId, int $days = 90, int $limit = 5): Collection
    {
        $bid = $business->id;
        $from = Carbon::now()->subDays($days);

        $rows = DB::table('sale_items as a')
            ->join('sale_items as b', function ($j) {
                $j->on('a.sale_id', '=', 'b.sale_id')
                  ->whereColumn('a.product_id', '!=', 'b.product_id');
            })
            ->join('sales', 'sales.id', '=', 'a.sale_id')
            ->where('a.business_id', $bid)
            ->where('a.product_id', $productId)
            ->where('sales.status', '!=', 'anulada')
            ->where('sales.sale_date', '>=', $from)
            ->select('b.product_id', DB::raw('COUNT(DISTINCT a.sale_id) as n'))
            ->groupBy('b.product_id')
            ->orderByDesc('n')
            ->limit($limit)
            ->get();

        $names = DB::table('products')
            ->where('business_id', $bid)
            ->whereIn('id', $rows->pluck('product_id'))
            ->pluck('name', 'id');

        return $rows->map(fn ($r) => (object) [
            'product_id' => $r->product_id,
            'name' => $names[$r->product_id] ?? '?',
            'count' => (int) $r->n,
        ]);
    }
}
