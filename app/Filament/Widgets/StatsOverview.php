<?php

namespace App\Filament\Widgets;

use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\SalesDailySummaryService;
use App\Support\DashboardPeriod;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return \App\Support\DashboardAccess::allowed();
    }

    protected function getStats(): array
    {
        $period = DashboardPeriod::fromFilters($this->filters);
        $from = $period['from'];
        $to = $period['to'];
        $label = $period['label'];

        $summaries = app(SalesDailySummaryService::class);
        $now = $summaries->totalsBetween($from, $to);
        $prev = $summaries->totalsBetween($period['prev_from'], $period['prev_to']);

        $sales = $now['total'];
        $tickets = $now['tickets'];
        $cogs = $now['cogs'];
        $avgTicket = $tickets > 0 ? round($sales / $tickets, 2) : 0.0;

        $prevSales = $prev['total'];
        $salesDiff = $prevSales > 0
            ? (($sales - $prevSales) / $prevSales) * 100
            : ($sales > 0 ? 100.0 : 0.0);

        $expenses = (float) Expense::query()
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('created_at', [$from, $to]);
            })
            ->paid()
            ->sum('amount');

        $net = round($sales - $cogs - $expenses, 2);

        $pendingOrders = Order::query()
            ->whereIn('status', ['pendiente', 'en_preparacion', 'lista'])
            ->count();

        $outOfStock = Product::query()
            ->where('track_stock', true)
            ->where('is_active', true)
            ->where('stock', '<=', 0)
            ->count();

        $lowStock = Product::query()
            ->where('track_stock', true)
            ->where('is_active', true)
            ->where('min_stock', '>', 0)
            ->whereColumn('stock', '<=', 'min_stock')
            ->count();

        $tenant = Filament::getTenant();
        $ordersUrl = $tenant
            ? route('filament.admin.resources.orders.index', ['tenant' => $tenant])
            : null;
        $productsUrl = $tenant
            ? route('filament.admin.resources.products.index', ['tenant' => $tenant])
            : null;

        $vsPrev = $prevSales > 0
            ? (abs($salesDiff) >= 0.1
                ? number_format(abs($salesDiff), 1).'% '.($salesDiff >= 0 ? 'más' : 'menos').' que el período anterior'
                : 'Igual que el período anterior')
            : ($sales > 0 ? 'Sin ventas en el período anterior' : 'Sin ventas');

        $stats = [
            Stat::make('Ventas · '.$label, 'RD$ '.number_format($sales, 2))
                ->description($vsPrev)
                ->descriptionIcon($salesDiff >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($salesDiff >= 0 ? 'success' : 'danger')
                ->chart($this->sparkline($from, $to)),

            Stat::make('Tickets', (string) $tickets)
                ->description($tickets > 0
                    ? 'Promedio RD$ '.number_format($avgTicket, 2).' por ticket'
                    : 'Sin ventas en '.$label)
                ->descriptionIcon('heroicon-m-receipt-percent')
                ->color($tickets > 0 ? 'primary' : 'gray'),

            Stat::make('Ganancia · '.$label, 'RD$ '.number_format($net, 2))
                ->description('Ventas − costo (RD$ '.number_format($cogs, 2).') − gastos (RD$ '.number_format($expenses, 2).')')
                ->descriptionIcon('heroicon-m-calculator')
                ->color($net >= 0 ? 'success' : 'danger'),

            Stat::make('Órdenes abiertas', (string) $pendingOrders)
                ->description('Pendiente / cocina / lista')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingOrders > 0 ? 'warning' : 'success')
                ->url($ordersUrl),

            Stat::make('Sin stock', (string) $outOfStock)
                ->description($lowStock > 0
                    ? $lowStock.' bajo el mínimo · reponer'
                    : 'Productos en cero')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($outOfStock > 0 ? 'danger' : ($lowStock > 0 ? 'warning' : 'success'))
                ->url($productsUrl),
        ];

        if ($tenant && method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy()) {
            $expired = ProductBatch::query()
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<', now()->toDateString())
                ->where('quantity', '>', 0)
                ->count();
            $near = ProductBatch::query()
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '>=', now()->toDateString())
                ->whereDate('expires_at', '<=', now()->addDays(30)->toDateString())
                ->where('quantity', '>', 0)
                ->count();

            $stats[] = Stat::make('Lotes vencidos', (string) $expired)
                ->description($near.' por vencer en 30 días')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($expired > 0 ? 'danger' : ($near > 0 ? 'warning' : 'success'));
        }

        return $stats;
    }

    /**
     * @return list<float>
     */
    protected function sparkline(Carbon $from, Carbon $to): array
    {
        $summaries = app(SalesDailySummaryService::class);
        $values = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        $maxPoints = 14;
        $span = max(1, $cursor->diffInDays($end) + 1);
        $step = $span > $maxPoints ? (int) ceil($span / $maxPoints) : 1;

        while ($cursor->lte($end)) {
            $chunkEnd = $cursor->copy()->addDays($step - 1)->endOfDay();
            if ($chunkEnd->gt($to)) {
                $chunkEnd = $to->copy();
            }
            $values[] = $summaries->totalsBetween($cursor, $chunkEnd)['total'];
            $cursor->addDays($step);
        }

        return $values;
    }
}
