<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ReceivableResource;
use App\Models\Receivable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReceivablesWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = filament()->getTenant();

        return $tenant ? $tenant->hasModule('receivables') : false;
    }

    protected function getStats(): array
    {
        $pending = (float) Receivable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->sum('balance');

        $overdue = (float) Receivable::query()
            ->where('status', 'vencida')
            ->where('balance', '>', 0)
            ->sum('balance');

        $count = (int) Receivable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->count();

        $label = match (filament()->getTenant()?->business_type) {
            'colmado' => 'Fiado pendiente',
            default => 'Por cobrar',
        };

        $stat = Stat::make($label, 'RD$ '.number_format($pending, 2))
            ->description($count.' cuentas · Vencido: RD$ '.number_format($overdue, 2))
            ->descriptionIcon('heroicon-m-clock')
            ->color($overdue > 0 ? 'danger' : ($pending > 0 ? 'warning' : 'success'));

        try {
            $stat->url(ReceivableResource::getUrl('index'));
        } catch (\Throwable) {
        }

        return [$stat];
    }
}
