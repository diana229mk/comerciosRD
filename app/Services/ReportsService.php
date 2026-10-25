<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Datos para la página de Reportes Avanzados (multi-tenant vía Global Scope).
 */
class ReportsService
{
    /**
     * @return array{
     *     from: Carbon,
     *     to: Carbon,
     *     sales_summary: array{count: int, total: float, avg: float, tax: float, subtotal: float, discount: float},
     *     sales_by_day: list<array{date: string, label: string, count: int, total: float}>,
     *     top_products: list<array{name: string, qty: float, revenue: float, cost: float, profit: float}>,
     *     margins: array{revenue: float, cost: float, profit: float, margin_pct: float},
     *     receivables: array{open_count: int, open_balance: float, overdue_count: int, overdue_balance: float, due_soon: list<array>},
     *     payables: array{open_count: int, open_balance: float, overdue_count: int, overdue_balance: float, due_soon: list<array>},
     *     inventory: array{sku_count: int, units: float, cost_value: float, retail_value: float},
     *     cash_sessions: list<array>,
     * }
     */
    public function build(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        return [
            'from' => $from,
            'to' => $to,
            'sales_summary' => $this->salesSummary($from, $to),
            'sales_by_day' => $this->salesByDay($from, $to),
            'top_products' => $this->topProducts($from, $to),
            'margins' => $this->margins($from, $to),
            'receivables' => $this->receivables(),
            'payables' => $this->payables(),
            'inventory' => $this->inventoryValued(),
            'cash_sessions' => $this->cashSessions($from, $to),
        ];
    }

    /**
     * @return array{count: int, total: float, avg: float, tax: float, subtotal: float, discount: float}
     */
    protected function salesSummary(Carbon $from, Carbon $to): array
    {
        $q = $this->salesInPeriodQuery($from, $to);

        $count = (int) $q->count();
        $total = (float) (clone $q)->sum('total');
        $tax = (float) (clone $q)->sum('tax');
        $subtotal = (float) (clone $q)->sum('subtotal');
        $discount = (float) (clone $q)->sum('discount');

        return [
            'count' => $count,
            'total' => round($total, 2),
            'avg' => $count > 0 ? round($total / $count, 2) : 0.0,
            'tax' => round($tax, 2),
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
        ];
    }

    protected function salesInPeriodQuery(Carbon $from, Carbon $to): Builder
    {
        return Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            });
    }

    /**
     * @return list<array{date: string, label: string, count: int, total: float}>
     */
    protected function salesByDay(Carbon $from, Carbon $to): array
    {
        $rows = $this->salesInPeriodQuery($from, $to)
            ->selectRaw('DATE(COALESCE(sale_date, sold_at, created_at)) as d')
            ->selectRaw('COUNT(*) as c')
            ->selectRaw('COALESCE(SUM(total), 0) as t')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->d] = [
                'count' => (int) $row->c,
                'total' => round((float) $row->t, 2),
            ];
        }

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        // Limitar a 93 días para no explotar la UI
        $guard = 0;
        while ($cursor->lte($end) && $guard < 93) {
            $key = $cursor->toDateString();
            $out[] = [
                'date' => $key,
                'label' => $cursor->format('d/m'),
                'count' => $map[$key]['count'] ?? 0,
                'total' => $map[$key]['total'] ?? 0.0,
            ];
            $cursor->addDay();
            $guard++;
        }

        return $out;
    }

    /**
     * @return list<array{name: string, qty: float, revenue: float, cost: float, profit: float}>
     */
    protected function topProducts(Carbon $from, Carbon $to, int $limit = 10): array
    {
        $sales = $this->salesInPeriodQuery($from, $to)
            ->select(['sales.id', 'sales.subtotal', 'sales.discount']);

        $items = SaleItem::query()
            ->joinSub($sales, 'report_sales', function ($join): void {
                $join->on('report_sales.id', '=', 'sale_items.sale_id');
            })
            ->selectRaw('sale_items.product_name as product_name')
            ->selectRaw('SUM(sale_items.quantity) as qty')
            ->selectRaw(
                'SUM(CASE WHEN report_sales.subtotal > 0 '
                .'THEN GREATEST(0, sale_items.subtotal - report_sales.discount * sale_items.subtotal / report_sales.subtotal) '
                .'ELSE 0 END) as revenue'
            )
            ->selectRaw('SUM(COALESCE(sale_items.unit_cost, 0) * sale_items.quantity) as cost')
            ->groupBy('sale_items.product_name')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        return $items->map(function ($row) {
            $revenue = round((float) $row->revenue, 2);
            $cost = round((float) $row->cost, 2);

            return [
                'name' => (string) $row->product_name,
                'qty' => round((float) $row->qty, 3),
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 2),
            ];
        })->all();
    }

    /**
     * @return array{revenue: float, cost: float, profit: float, margin_pct: float}
     */
    protected function margins(Carbon $from, Carbon $to): array
    {
        $sales = $this->salesInPeriodQuery($from, $to)
            ->select(['sales.id', 'sales.subtotal', 'sales.discount']);

        $row = SaleItem::query()
            ->joinSub($sales, 'report_sales', function ($join): void {
                $join->on('report_sales.id', '=', 'sale_items.sale_id');
            })
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN report_sales.subtotal > 0 '
                .'THEN GREATEST(0, sale_items.subtotal - report_sales.discount * sale_items.subtotal / report_sales.subtotal) '
                .'ELSE 0 END), 0) as revenue'
            )
            ->selectRaw('COALESCE(SUM(COALESCE(sale_items.unit_cost, 0) * sale_items.quantity), 0) as cost')
            ->first();

        $revenue = round((float) ($row->revenue ?? 0), 2);
        $cost = round((float) ($row->cost ?? 0), 2);
        $profit = round($revenue - $cost, 2);
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0;

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $profit,
            'margin_pct' => $margin,
        ];
    }

    /**
     * @return array{open_count: int, open_balance: float, overdue_count: int, overdue_balance: float, due_soon: list<array>}
     */
    protected function receivables(): array
    {
        $open = Receivable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0);

        $openCount = (int) (clone $open)->count();
        $openBalance = round((float) (clone $open)->sum('balance'), 2);

        $overdue = (clone $open)->whereDate('due_date', '<', now()->toDateString());
        $overdueCount = (int) (clone $overdue)->count();
        $overdueBalance = round((float) (clone $overdue)->sum('balance'), 2);

        $dueSoon = (clone $open)
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(15)->toDateString()])
            ->orderBy('due_date')
            ->limit(8)
            ->get(['id', 'customer_id', 'balance', 'due_date', 'status'])
            ->load('customer:id,name')
            ->map(fn ($r) => [
                'customer' => $r->customer?->name ?? 'Cliente',
                'balance' => (float) $r->balance,
                'due_date' => $r->due_date?->format('d/m/Y') ?? '—',
                'status' => (string) $r->status,
            ])
            ->all();

        return [
            'open_count' => $openCount,
            'open_balance' => $openBalance,
            'overdue_count' => $overdueCount,
            'overdue_balance' => $overdueBalance,
            'due_soon' => $dueSoon,
        ];
    }

    /**
     * @return array{open_count: int, open_balance: float, overdue_count: int, overdue_balance: float, due_soon: list<array>}
     */
    protected function payables(): array
    {
        $open = Payable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0);

        $openCount = (int) (clone $open)->count();
        $openBalance = round((float) (clone $open)->sum('balance'), 2);

        $overdue = (clone $open)->whereDate('due_date', '<', now()->toDateString());
        $overdueCount = (int) (clone $overdue)->count();
        $overdueBalance = round((float) (clone $overdue)->sum('balance'), 2);

        $dueSoon = (clone $open)
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(15)->toDateString()])
            ->orderBy('due_date')
            ->limit(8)
            ->get(['id', 'supplier_name', 'balance', 'due_date', 'status'])
            ->map(fn ($p) => [
                'supplier' => $p->supplier_name ?: 'Proveedor',
                'balance' => (float) $p->balance,
                'due_date' => $p->due_date?->format('d/m/Y') ?? '—',
                'status' => (string) $p->status,
            ])
            ->all();

        return [
            'open_count' => $openCount,
            'open_balance' => $openBalance,
            'overdue_count' => $overdueCount,
            'overdue_balance' => $overdueBalance,
            'due_soon' => $dueSoon,
        ];
    }

    /**
     * @return array{sku_count: int, units: float, cost_value: float, retail_value: float}
     */
    protected function inventoryValued(): array
    {
        $row = Product::query()
            ->where('is_active', true)
            ->where('track_stock', true)
            ->selectRaw('COUNT(*) as sku_count')
            ->selectRaw('COALESCE(SUM(stock), 0) as units')
            ->selectRaw('COALESCE(SUM(stock * COALESCE(cost, 0)), 0) as cost_value')
            ->selectRaw('COALESCE(SUM(stock * COALESCE(price, 0)), 0) as retail_value')
            ->first();

        return [
            'sku_count' => (int) ($row->sku_count ?? 0),
            'units' => round((float) ($row->units ?? 0), 3),
            'cost_value' => round((float) ($row->cost_value ?? 0), 2),
            'retail_value' => round((float) ($row->retail_value ?? 0), 2),
        ];
    }

    /**
     * @return list<array{opened_at: string, closed_at: string, user: string, opening: float, sales: float, expected: float, closing: float|null, difference: float|null, status: string}>
     */
    protected function cashSessions(Carbon $from, Carbon $to): array
    {
        if (! class_exists(CashSession::class)) {
            return [];
        }

        return CashSession::query()
            ->with('user:id,name')
            ->whereBetween('opened_at', [$from, $to])
            ->orderByDesc('opened_at')
            ->limit(20)
            ->get()
            ->map(fn (CashSession $s) => [
                'opened_at' => $s->opened_at?->format('d/m/Y H:i') ?? '—',
                'closed_at' => $s->closed_at?->format('d/m/Y H:i') ?? '—',
                'user' => $s->user?->name ?? '—',
                'opening' => (float) $s->opening_amount,
                'sales' => (float) $s->total_sales_cash,
                'expected' => (float) ($s->expected_amount ?? 0),
                'closing' => $s->closing_amount !== null ? (float) $s->closing_amount : null,
                'difference' => $s->difference !== null ? (float) $s->difference : null,
                'status' => $s->status === 'open' ? 'Abierta' : 'Cerrada',
            ])
            ->all();
    }
}
