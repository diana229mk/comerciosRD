<?php

namespace App\Services;

use App\Enums\OrderType;
use App\Enums\SaleStatus;
use App\Models\AccountPayable;
use App\Models\AccountReceivable;
use App\Models\Business;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Métricas del dashboard multi-tenant.
 * Todas las consultas pasan por Global Scope de BelongsToBusiness
 * (o filtran business_id cuando usan withoutBusinessScope en agregados).
 */
class DashboardMetricsService
{
    public function __construct(
        protected Business $business
    ) {}

    public static function for(?Business $business): ?self
    {
        return $business ? new self($business) : null;
    }

    // ── KPIs ──────────────────────────────────────────────────────────

    /**
     * @return array{
     *   sales_today: float,
     *   sales_yesterday: float,
     *   sales_today_change_pct: float|null,
     *   sales_month: float,
     *   sales_goal: float,
     *   sales_goal_pct: float|null,
     *   cogs_month: float,
     *   expenses_month: float,
     *   net_profit_month: float,
     *   cash_flow: float,
     *   orders_pending_total: int,
     *   orders_by_name: int,
     *   orders_by_number: int,
     * }
     */
    public function kpis(): array
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        $salesToday = $this->sumCompletedSalesBetween(
            now()->startOfDay(),
            now()->endOfDay()
        );
        $salesYesterday = $this->sumCompletedSalesBetween(
            now()->subDay()->startOfDay(),
            now()->subDay()->endOfDay()
        );

        $monthTotals = app(SalesDailySummaryService::class)->totalsBetween(
            now()->startOfMonth(),
            now()->endOfMonth()
        );

        $salesMonth = $monthTotals['total'];
        $cogsMonth = $monthTotals['cogs'];
        $expensesMonth = (float) Expense::query()
            ->paid()
            ->betweenDates($monthStart, $monthEnd)
            ->sum('amount');

        $goal = $this->business->monthlySalesGoal();
        $changePct = $salesYesterday > 0
            ? round((($salesToday - $salesYesterday) / $salesYesterday) * 100, 1)
            : ($salesToday > 0 ? 100.0 : null);

        $goalPct = $goal > 0
            ? round(min(100, ($salesMonth / $goal) * 100), 1)
            : null;

        $ordersByName = Order::query()->open()->ofType(OrderType::PorNombre)->count();
        $ordersByNumber = Order::query()->open()->ofType(OrderType::PorNumero)->count();

        // Flujo de caja simplificado: ventas del mes cobradas − gastos pagados
        // (lista para refinarse con caja inicial / CxC / CxP)
        $cashFlow = $salesMonth - $expensesMonth;

        return [
            'sales_today'            => $salesToday,
            'sales_yesterday'        => $salesYesterday,
            'sales_today_change_pct' => $changePct,
            'sales_month'            => $salesMonth,
            'sales_goal'             => $goal,
            'sales_goal_pct'         => $goalPct,
            'cogs_month'             => $cogsMonth,
            'expenses_month'         => $expensesMonth,
            'net_profit_month'       => $salesMonth - $cogsMonth - $expensesMonth,
            'cash_flow'              => $cashFlow,
            'orders_pending_total'   => $ordersByName + $ordersByNumber,
            'orders_by_name'         => $ordersByName,
            'orders_by_number'       => $ordersByNumber,
        ];
    }

    protected function sumCompletedSalesBetween(Carbon $from, Carbon $to): float
    {
        return app(SalesDailySummaryService::class)->totalsBetween($from, $to)['total'];
    }

    /**
     * Costo de mercancía vendida (COGS) = Σ (unit_cost × quantity).
     */
    protected function sumCogsBetween(Carbon $from, Carbon $to): float
    {
        return app(SalesDailySummaryService::class)->totalsBetween($from, $to)['cogs'];
    }

    // ── Alertas ───────────────────────────────────────────────────────

    /**
     * @return Collection<int, Product>
     */
    public function criticalStock(int $limit = 5): Collection
    {
        return Product::query()
            ->lowStock()
            ->active()
            ->orderByRaw('(stock - min_stock) asc')
            ->orderBy('stock')
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'stock', 'min_stock', 'unit']);
    }

    /**
     * @return Collection<int, AccountPayable>
     */
    public function payablesDueSoon(int $days = 7, int $limit = 8): Collection
    {
        return AccountPayable::query()
            ->dueWithinDays($days)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, AccountReceivable>
     */
    public function receivablesPending(int $limit = 8): Collection
    {
        return AccountReceivable::query()
            ->open()
            ->orderByRaw('(amount - amount_paid) desc')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Product>
     */
    public function expiringProducts(int $days = 30, int $limit = 8): Collection
    {
        return Product::query()
            ->active()
            ->expiringSoon($days)
            ->limit($limit)
            ->get(['id', 'name', 'sku', 'stock', 'expires_at', 'unit']);
    }

    // ── Gráficos ──────────────────────────────────────────────────────

    /**
     * Ventas diarias últimos 30 días.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function salesLast30Days(): array
    {
        $from = now()->subDays(29)->startOfDay();
        $to = now()->endOfDay();

        $rows = Sale::query()
            ->completed()
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw('DATE(sold_at) as day, SUM(total) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        $labels = [];
        $values = [];

        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $key = $date->toDateString();
            $labels[] = $date->format('d/m');
            $values[] = round((float) ($rows[$key] ?? 0), 2);
        }

        return compact('labels', 'values');
    }

    /**
     * Top productos por cantidad vendida (mes actual).
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function topProducts(int $limit = 10): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $rows = SaleItem::query()
            ->whereHas('sale', function ($q) use ($from, $to) {
                $q->where('status', SaleStatus::Completada)
                    ->whereBetween('sold_at', [$from, $to]);
            })
            ->selectRaw('product_name, SUM(quantity) as qty')
            ->groupBy('product_name')
            ->orderByDesc('qty')
            ->limit($limit)
            ->get();

        return [
            'labels' => $rows->pluck('product_name')->map(
                fn ($n) => mb_strimwidth((string) $n, 0, 22, '…')
            )->all(),
            'values' => $rows->pluck('qty')->map(fn ($v) => round((float) $v, 2))->all(),
        ];
    }

    /**
     * Gastos del mes por categoría.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function expensesByCategory(): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();

        $rows = Expense::query()
            ->paid()
            ->betweenDates($from, $to)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $labels = [];
        $values = [];

        foreach ($rows as $row) {
            $cat = $row->category;
            $labels[] = $cat instanceof \BackedEnum
                ? (method_exists($cat, 'label') ? $cat->label() : $cat->value)
                : (string) $cat;
            $values[] = round((float) $row->total, 2);
        }

        return compact('labels', 'values');
    }

    /**
     * Ventas agregadas por hora del día (últimos 14 días) para turnos.
     *
     * @return array{labels: list<string>, values: list<float>}
     */
    public function salesByHour(): array
    {
        $from = now()->subDays(13)->startOfDay();
        $to = now()->endOfDay();

        $driver = DB::connection()->getDriverName();
        $hourExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%H', sold_at) AS INTEGER)",
            'pgsql'  => 'EXTRACT(HOUR FROM sold_at)',
            default  => 'HOUR(sold_at)',
        };

        $rows = Sale::query()
            ->completed()
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw("{$hourExpr} as hour_of_day, SUM(total) as total")
            ->groupBy('hour_of_day')
            ->orderBy('hour_of_day')
            ->pluck('total', 'hour_of_day');

        $labels = [];
        $values = [];

        for ($h = 0; $h < 24; $h++) {
            $labels[] = sprintf('%02d:00', $h);
            $values[] = round((float) ($rows[$h] ?? $rows[(string) $h] ?? 0), 2);
        }

        return compact('labels', 'values');
    }
}
