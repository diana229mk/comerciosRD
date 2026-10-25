<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CashSessionResource;
use App\Models\CashSession;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OpenCashWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('cash_register') : false;
    }

    protected function getStats(): array
    {
        $open = CashSession::query()
            ->open()
            ->with('user:id,name')
            ->latest('opened_at')
            ->get();

        if ($open->isEmpty()) {
            $stat = Stat::make('Caja', 'Cerrada')
                ->description('No hay cajas abiertas')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('gray');

            try {
                $stat->url(CashSessionResource::getUrl('index'));
            } catch (\Throwable) {
            }

            return [$stat];
        }

        $session = $open->first();
        $expected = $open->sum(fn (CashSession $s) => $s->calculateExpectedAmount());
        $who = $open->map(fn (CashSession $s) => $s->user?->name ?: 'Cajero')->unique()->implode(', ');
        $label = $open->count() === 1 ? 'Caja abierta' : $open->count().' cajas abiertas';

        $stat = Stat::make($label, 'RD$ '.number_format((float) $expected, 2))
            ->description(
                $who
                .' · Apertura: RD$ '.number_format((float) $open->sum('opening_amount'), 2)
                .' · Efectivo ventas: RD$ '.number_format((float) $open->sum('total_sales_cash'), 2)
            )
            ->descriptionIcon('heroicon-m-banknotes')
            ->color('success');

        try {
            $stat->url($open->count() === 1
                ? CashSessionResource::getUrl('view', ['record' => $session])
                : CashSessionResource::getUrl('index'));
        } catch (\Throwable) {
        }

        return [$stat];
    }
}
