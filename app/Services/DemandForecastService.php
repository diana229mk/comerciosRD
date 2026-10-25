<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Predicción de demanda usando media móvil ponderada + tendencia lineal simple.
 * No requiere APIs externas — todo se calcula sobre sale_items.
 */
class DemandForecastService
{
    /**
     * Analiza demanda histórica y proyecta consumo del próximo período.
     * Retorna colección ordenada por urgencia de reorden (menor cobertura primero).
     *
     * @return Collection<int, object>  con: product_id, name, sku, stock, min_stock,
     *   avg_30, avg_60, avg_90, trend, forecast_30d, days_of_stock, suggested_qty, urgency
     */
    public function analyze(Business $business, int $horizonDays = 30): Collection
    {
        $bid = $business->id;
        $now = Carbon::now();

        $windows = [
            'avg_30' => $now->copy()->subDays(30),
            'avg_60' => $now->copy()->subDays(60),
            'avg_90' => $now->copy()->subDays(90),
        ];

        $totals = [];
        foreach ($windows as $key => $from) {
            $rows = DB::table('sale_items')
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sale_items.business_id', $bid)
                ->where('sales.status', '!=', 'anulada')
                ->where('sales.sale_date', '>=', $from)
                ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as qty'))
                ->groupBy('sale_items.product_id')
                ->get()
                ->keyBy('product_id');
            $totals[$key] = $rows;
        }

        $products = DB::table('products')
            ->where('business_id', $bid)
            ->where('is_active', true)
            ->select('id', 'name', 'sku', 'stock', 'min_stock', 'max_stock', 'cost')
            ->get();

        return $products->map(function ($p) use ($totals, $horizonDays) {
            $q30 = (float) ($totals['avg_30']->get($p->id)->qty ?? 0);
            $q60 = (float) ($totals['avg_60']->get($p->id)->qty ?? 0);
            $q90 = (float) ($totals['avg_90']->get($p->id)->qty ?? 0);

            $avg30 = $q30 / 30;
            $avg60 = $q60 / 60;
            $avg90 = $q90 / 90;

            // Tendencia: variación de la ventana reciente vs histórica
            $trend = $avg90 > 0 ? round((($avg30 - $avg90) / $avg90) * 100, 1) : 0;

            // Media ponderada: reciente pesa más (0.5, 0.3, 0.2)
            $weightedDaily = ($avg30 * 0.5) + ($avg60 * 0.3) + ($avg90 * 0.2);
            // Ajuste por tendencia (topeado a ±50% para no explotar en outliers)
            $trendFactor = 1 + max(-0.5, min(0.5, $trend / 100));
            $forecastDaily = $weightedDaily * $trendFactor;
            $forecast = round($forecastDaily * $horizonDays, 2);

            $stock = (float) $p->stock;
            $daysOfStock = $forecastDaily > 0 ? round($stock / $forecastDaily, 1) : null;

            // Sugerencia de compra: proyección + buffer 20% - stock actual
            $target = ceil($forecast * 1.2);
            $suggested = max(0, $target - $stock);

            // Urgencia: 0 = ok, 1 = advertencia (por debajo de horizonte), 2 = crítico (<7d)
            $urgency = 0;
            if ($forecastDaily > 0) {
                if ($daysOfStock !== null && $daysOfStock < 7) $urgency = 2;
                elseif ($daysOfStock !== null && $daysOfStock < $horizonDays) $urgency = 1;
            }

            // Además: si stock <= min_stock → siempre urgencia mínima 1
            if ((float) $p->min_stock > 0 && $stock <= (float) $p->min_stock) {
                $urgency = max($urgency, 1);
            }

            return (object) [
                'product_id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'stock' => $stock,
                'min_stock' => (float) $p->min_stock,
                'cost' => (float) $p->cost,
                'avg_30' => round($avg30, 3),
                'avg_60' => round($avg60, 3),
                'avg_90' => round($avg90, 3),
                'trend' => $trend,
                'forecast_daily' => round($forecastDaily, 3),
                'forecast' => $forecast,
                'days_of_stock' => $daysOfStock,
                'suggested_qty' => (int) $suggested,
                'suggested_cost' => round($suggested * (float) $p->cost, 2),
                'urgency' => $urgency,
            ];
        })
        ->filter(fn ($r) => $r->urgency > 0 || $r->suggested_qty > 0)
        ->sortBy(fn ($r) => [-$r->urgency, $r->days_of_stock ?? PHP_INT_MAX])
        ->values();
    }
}
