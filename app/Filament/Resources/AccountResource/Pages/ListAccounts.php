<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Models\Account;
use App\Services\AccountingService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('seed_defaults')
                ->label('Cargar plan básico')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Cargar plan de cuentas básico')
                ->modalDescription('Crea las cuentas del sistema (Caja, Ventas, Gastos, etc.) si aún no existen.')
                ->visible(fn (): bool => AccountResource::canCreate())
                ->action(function (): void {
                    $tenant = filament()->getTenant();
                    if (! $tenant) {
                        return;
                    }

                    $before = Account::query()->count();
                    AccountingService::createDefaultAccounts($tenant);
                    $after = Account::query()->count();
                    $created = max(0, $after - $before);

                    Notification::make()
                        ->title($created > 0 ? "Se crearon / completaron cuentas ({$after} en total)" : 'El plan ya estaba completo')
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make()
                ->label('Nueva cuenta'),
        ];
    }
}
