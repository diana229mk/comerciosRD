<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTenantDashboard;
use App\Support\DashboardWidgetRegistry;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithTenantDashboard;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    protected static function widgetKey(): ?string
    {
        return DashboardWidgetRegistry::KPI;
    }

    protected function getStats(): array
    {
        $metrics = $this->metrics();

        if (! $metrics) {
            return [];
        }

        $k = $metrics->kpis();

        $todayChange = $k['sales_today_change_pct'];
        $todayDesc = $todayChange === null
            ? 'Sin ventas ayer para comparar'
            : (($todayChange >= 0 ? '▲ ' : '▼ ').abs($todayChange).'% vs ayer');

        $goal = $k['sales_goal'];
        $goalDesc = $goal > 0
            ? 'Meta: '.Money::format($goal).' · '.($k['sales_goal_pct'] ?? 0).'%'
            : 'Define meta en Personalizar panel';

        $profit = $k['net_profit_month'];
        $profitColor = $profit >= 0 ? 'success' : 'danger';

        return [
            Stat::make('Ventas de hoy', Money::format($k['sales_today']))
                ->description($todayDesc)
                ->descriptionIcon(
                    $todayChange !== null && $todayChange < 0
                        ? 'heroicon-m-arrow-trending-down'
                        : 'heroicon-m-arrow-trending-up'
                )
                ->color($todayChange !== null && $todayChange < 0 ? 'danger' : 'success')
                ->chart($this->sparklineToday()),

            Stat::make('Ventas del mes', Money::format($k['sales_month']))
                ->description($goalDesc)
                ->descriptionIcon('heroicon-m-flag')
                ->color('primary'),

            Stat::make('Ganancia neta del mes', Money::format($profit))
                ->description(
                    'Ventas − COGS ('.Money::compact($k['cogs_month']).') − Gastos ('.Money::compact($k['expenses_month']).')'
                )
                ->descriptionIcon('heroicon-m-calculator')
                ->color($profitColor),

            Stat::make('Flujo de caja', Money::format($k['cash_flow']))
                ->description('Ventas del mes − gastos pagados')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($k['cash_flow'] >= 0 ? 'success' : 'warning'),

            Stat::make('Órdenes pendientes', (string) $k['orders_pending_total'])
                ->description(
                    "Por nombre: {$k['orders_by_name']} · Por número: {$k['orders_by_number']}"
                )
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($k['orders_pending_total'] > 0 ? 'warning' : 'gray'),
        ];
    }

    /**
     * Mini sparkline: ventas de las últimas 7 horas (si hay datos del día).
     *
     * @return list<float>
     */
    protected function sparklineToday(): array
    {
        $metrics = $this->metrics();
        if (! $metrics) {
            return [0, 0, 0, 0, 0, 0, 0];
        }

        // Reutiliza serie de 30 días recortada a 7 puntos para el chart del stat
        $series = $metrics->salesLast30Days()['values'];

        return array_slice($series, -7);
    }

    protected function getColumns(): int
    {
        return 5;
    }
}
