<?php

namespace App\Filament\Master\Resources\SubscriptionInvoiceResource\Pages;

use App\Filament\Master\Resources\SubscriptionInvoiceResource;
use App\Services\SubscriptionBillingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionInvoices extends ListRecords
{
    protected static string $resource = SubscriptionInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generar')
                ->label('Generar cuotas del mes')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $n = app(SubscriptionBillingService::class)->generateOpenInvoices();
                    Notification::make()
                        ->title('Cuotas actualizadas')
                        ->body($n.' negocios activos revisados.')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make()->label('Nueva cuota'),
        ];
    }
}
