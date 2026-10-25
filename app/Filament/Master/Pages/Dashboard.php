<?php

namespace App\Filament\Master\Pages;

use App\Filament\Master\Widgets\MasterStatsOverview;
use App\Filament\Master\Widgets\SubscriptionManagerTable;
use App\Filament\Master\Widgets\SubscriptionsByPlan;
use App\Services\SubscriptionBillingService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Suscripciones';

    protected static ?string $title = 'Manejador de suscripciones';

    protected static ?int $navigationSort = -2;

    public function mount(): void
    {
        app(SubscriptionBillingService::class)->generateOpenInvoices();
    }

    public function getSubheading(): ?string
    {
        return 'Ingresos de ComerciosRD (planes Esencial / Equipo / Completo). Las ventas POS las ve cada local.';
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            MasterStatsOverview::class,
            SubscriptionsByPlan::class,
            SubscriptionManagerTable::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generar')
                ->label('Generar cuotas del mes')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $n = app(SubscriptionBillingService::class)->generateOpenInvoices();
                    Notification::make()
                        ->title('Cuotas del mes listas')
                        ->body($n.' negocios activos revisados.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
