<?php

namespace App\Filament\Master\Widgets;

use App\Models\Business;
use App\Services\SubscriptionBillingService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MasterStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $billing = app(SubscriptionBillingService::class);
        $billing->generateOpenInvoices();

        $total = Business::count();
        $active = Business::query()->where('is_active', true)->count();
        $suspended = $total - $active;

        $mrr = $billing->expectedMonthly();
        $collected = $billing->collectedThisMonth();
        $outstanding = $billing->outstanding();
        $overdue = $billing->overdueAmount();
        $pct = $mrr > 0 ? round(($collected / $mrr) * 100) : 0;

        return [
            Stat::make('Negocios en plan', (string) $active)
                ->description($suspended.' suspendidos · '.$total.' en cartera')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('A generar este mes', 'RD$ '.number_format($mrr, 0))
                ->description('Planes + asientos extra de locales activos')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Ya cobrado', 'RD$ '.number_format($collected, 0))
                ->description($pct.'% del mes · registrar pagos abajo')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Por cobrar', 'RD$ '.number_format($outstanding, 0))
                ->description($overdue > 0
                    ? 'RD$ '.number_format($overdue, 0).' vencidas'
                    : 'Al día')
                ->descriptionIcon('heroicon-m-clock')
                ->color($overdue > 0 ? 'danger' : ($outstanding > 0 ? 'warning' : 'success')),
        ];
    }
}
