<?php

namespace App\Filament\Widgets;

use App\Services\SalesDailySummaryService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesTodayWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        return true;
    }

    protected function getStats(): array
    {
        $summaries = app(SalesDailySummaryService::class);
        $today = $summaries->totalsBetween(now()->startOfDay(), now()->endOfDay())['total'];
        $yesterday = $summaries->totalsBetween(
            now()->subDay()->startOfDay(),
            now()->subDay()->endOfDay()
        )['total'];

        $diff = $yesterday > 0
            ? round((($today - $yesterday) / $yesterday) * 100, 1)
            : null;

        $description = $diff === null
            ? 'Sin ventas ayer para comparar'
            : ($diff >= 0 ? "+{$diff}% vs ayer" : "{$diff}% vs ayer");

        $color = $diff === null ? 'gray' : ($diff >= 0 ? 'success' : 'danger');

        return [
            Stat::make('Ventas de hoy', 'RD$ '.number_format($today, 2))
                ->description($description)
                ->descriptionIcon(
                    $diff !== null && $diff >= 0
                        ? 'heroicon-m-arrow-trending-up'
                        : 'heroicon-m-arrow-trending-down'
                )
                ->color($color)
                ->chart($this->lastSevenDaysChart()),
        ];
    }

    /**
     * @return list<float>
     */
    protected function lastSevenDaysChart(): array
    {
        $summaries = app(SalesDailySummaryService::class);
        $points = [];

        for ($i = 6; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $points[] = $summaries->totalsBetween(
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay()
            )['total'];
        }

        return $points;
    }
}
