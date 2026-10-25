<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesDaily;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resumen diario por negocio (+ sucursal).
 *
 * El cobro hace un UPSERT O(1). El dashboard suma estas filas en vez de
 * recorrer sale_items. Días sin fila (histórico previo a esta tabla) se
 * completan con un fallback a `sales` para no subcontar en el rollout.
 */
class SalesDailySummaryService
{
    /**
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    public function applyCompletedSale(Sale $sale): array
    {
        return $this->bump($sale, +1);
    }

    /**
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    public function applyCancelledSale(Sale $sale): array
    {
        return $this->bump($sale, -1);
    }

    /**
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    public function totalsBetween(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        if (! config('scale.dashboard_use_daily_summary', true)) {
            return $this->liveTotals($from, $to);
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $rows = SalesDaily::query()
            ->whereBetween('report_date', [$fromDate, $toDate])
            ->get(['report_date', 'tickets', 'subtotal', 'discount', 'tax', 'total', 'cogs']);

        $covered = $rows
            ->map(fn (SalesDaily $row) => $row->report_date?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();

        $summary = [
            'tickets' => (int) $rows->sum('tickets'),
            'subtotal' => round((float) $rows->sum('subtotal'), 2),
            'discount' => round((float) $rows->sum('discount'), 2),
            'tax' => round((float) $rows->sum('tax'), 2),
            'total' => round((float) $rows->sum('total'), 2),
            'cogs' => round((float) $rows->sum('cogs'), 2),
        ];

        $liveFrom = $from->copy();
        $liveTo = $to->copy();
        $live = $covered === []
            ? $this->liveTotals($liveFrom, $liveTo)
            : $this->liveTotalsExcludingDates($liveFrom, $liveTo, $covered);

        return [
            'tickets' => $summary['tickets'] + $live['tickets'],
            'subtotal' => round($summary['subtotal'] + $live['subtotal'], 2),
            'discount' => round($summary['discount'] + $live['discount'], 2),
            'tax' => round($summary['tax'] + $live['tax'], 2),
            'total' => round($summary['total'] + $live['total'], 2),
            'cogs' => round($summary['cogs'] + $live['cogs'], 2),
        ];
    }

    /**
     * Reconstruye el rango desde `sales` (corrección nocturna / backfill).
     */
    public function rebuild(string $businessId, Carbon $from, Carbon $to): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $sales = Sale::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereIn('status', [
                SaleStatus::Completada->value,
                SaleStatus::Completed->value,
            ])
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('sold_at', [$from, $to])
                    ->orWhereBetween('sale_date', [$from, $to]);
            })
            ->get(['id', 'business_id', 'branch_id', 'sold_at', 'sale_date', 'subtotal', 'discount', 'tax', 'total']);

        $cogsBySale = SaleItem::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereIn('sale_id', $sales->pluck('id'))
            ->selectRaw('sale_id, COALESCE(SUM(COALESCE(unit_cost, 0) * quantity), 0) as cogs')
            ->groupBy('sale_id')
            ->pluck('cogs', 'sale_id');

        $buckets = [];
        foreach ($sales as $sale) {
            $date = ($sale->sold_at ?? $sale->sale_date)?->toDateString();
            if ($date === null) {
                continue;
            }
            $branchKey = (string) ($sale->branch_id ?? '');
            $key = $branchKey.'|'.$date;
            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'branch_id' => $sale->branch_id,
                    'branch_key' => $branchKey,
                    'report_date' => $date,
                    'tickets' => 0,
                    'subtotal' => 0.0,
                    'discount' => 0.0,
                    'tax' => 0.0,
                    'total' => 0.0,
                    'cogs' => 0.0,
                ];
            }
            $buckets[$key]['tickets']++;
            $buckets[$key]['subtotal'] += (float) $sale->subtotal;
            $buckets[$key]['discount'] += (float) $sale->discount;
            $buckets[$key]['tax'] += (float) $sale->tax;
            $buckets[$key]['total'] += (float) $sale->total;
            $buckets[$key]['cogs'] += (float) ($cogsBySale[$sale->id] ?? 0);
        }

        SalesDaily::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->whereBetween('report_date', [$from->toDateString(), $to->toDateString()])
            ->delete();

        $now = now();
        foreach ($buckets as $bucket) {
            SalesDaily::withoutBusinessScope()->forceCreate([
                'business_id' => $businessId,
                'branch_id' => $bucket['branch_id'] ?: null,
                'branch_key' => $bucket['branch_key'],
                'report_date' => $bucket['report_date'],
                'tickets' => $bucket['tickets'],
                'subtotal' => round($bucket['subtotal'], 2),
                'discount' => round($bucket['discount'], 2),
                'tax' => round($bucket['tax'], 2),
                'total' => round($bucket['total'], 2),
                'cogs' => round($bucket['cogs'], 2),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return count($buckets);
    }

    /**
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    protected function bump(Sale $sale, int $direction): array
    {
        $sign = $direction >= 0 ? 1 : -1;
        $date = ($sale->sold_at ?? $sale->sale_date)?->toDateString() ?? now()->toDateString();
        $branchKey = (string) ($sale->branch_id ?? '');
        $cogs = $this->cogsForSale($sale);
        $delta = [
            'tickets' => $sign,
            'subtotal' => round($sign * (float) $sale->subtotal, 2),
            'discount' => round($sign * (float) $sale->discount, 2),
            'tax' => round($sign * (float) $sale->tax, 2),
            'total' => round($sign * (float) $sale->total, 2),
            'cogs' => round($sign * $cogs, 2),
        ];

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $affected = DB::update(
                'UPDATE sales_daily
                    SET tickets = tickets + ?,
                        subtotal = subtotal + ?,
                        discount = discount + ?,
                        tax = tax + ?,
                        total = total + ?,
                        cogs = cogs + ?,
                        updated_at = ?
                  WHERE business_id = ? AND branch_key = ? AND report_date = ?',
                [
                    $delta['tickets'],
                    $delta['subtotal'],
                    $delta['discount'],
                    $delta['tax'],
                    $delta['total'],
                    $delta['cogs'],
                    now(),
                    $sale->business_id,
                    $branchKey,
                    $date,
                ]
            );

            if ($affected > 0) {
                return $delta;
            }

            try {
                DB::table('sales_daily')->insert([
                    'id' => (string) Str::uuid(),
                    'business_id' => $sale->business_id,
                    'branch_id' => $sale->branch_id,
                    'branch_key' => $branchKey,
                    'report_date' => $date,
                    'tickets' => max(0, $delta['tickets']),
                    'subtotal' => max(0, $delta['subtotal']),
                    'discount' => max(0, $delta['discount']),
                    'tax' => max(0, $delta['tax']),
                    'total' => max(0, $delta['total']),
                    'cogs' => max(0, $delta['cogs']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $delta;
            } catch (QueryException) {
                // carrera: otro cajero insertó la fila; reintentar UPDATE
            }
        }

        return $delta;
    }

    protected function cogsForSale(Sale $sale): float
    {
        $sale->loadMissing('items');

        return round((float) $sale->items->sum(
            fn ($item) => (float) ($item->unit_cost ?? 0) * (float) $item->quantity
        ), 2);
    }

    /**
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    protected function liveTotals(Carbon $from, Carbon $to): array
    {
        $sales = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('sold_at', [$from, $to])
                    ->orWhereBetween('sale_date', [$from, $to]);
            });

        $tickets = (int) (clone $sales)->count();
        $subtotal = (float) (clone $sales)->sum('subtotal');
        $discount = (float) (clone $sales)->sum('discount');
        $tax = (float) (clone $sales)->sum('tax');
        $total = (float) (clone $sales)->sum('total');

        $cogs = (float) SaleItem::query()
            ->whereHas('sale', function ($q) use ($from, $to): void {
                $q->whereIn('status', [
                    SaleStatus::Completada->value,
                    SaleStatus::Completed->value,
                ])->where(function ($q2) use ($from, $to): void {
                    $q2->whereBetween('sold_at', [$from, $to])
                        ->orWhereBetween('sale_date', [$from, $to]);
                });
            })
            ->selectRaw('COALESCE(SUM(COALESCE(unit_cost, 0) * quantity), 0) as cogs')
            ->value('cogs');

        return [
            'tickets' => $tickets,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'cogs' => round($cogs, 2),
        ];
    }

    /**
     * @param  list<string>  $dates
     * @return array{tickets: int, subtotal: float, discount: float, tax: float, total: float, cogs: float}
     */
    protected function liveTotalsExcludingDates(Carbon $from, Carbon $to, array $dates): array
    {
        if ($dates === []) {
            return $this->liveTotals($from, $to);
        }

        $sales = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('sold_at', [$from, $to])
                    ->orWhereBetween('sale_date', [$from, $to]);
            })
            ->whereNotIn(DB::raw('DATE(COALESCE(sold_at, sale_date))'), $dates);

        $tickets = (int) (clone $sales)->count();
        $total = (float) (clone $sales)->sum('total');
        $subtotal = (float) (clone $sales)->sum('subtotal');
        $discount = (float) (clone $sales)->sum('discount');
        $tax = (float) (clone $sales)->sum('tax');

        $ids = (clone $sales)->pluck('id');
        $cogs = $ids->isEmpty()
            ? 0.0
            : (float) SaleItem::query()
                ->whereIn('sale_id', $ids)
                ->selectRaw('COALESCE(SUM(COALESCE(unit_cost, 0) * quantity), 0) as cogs')
                ->value('cogs');

        return [
            'tickets' => $tickets,
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'cogs' => round($cogs, 2),
        ];
    }
}
