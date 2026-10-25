<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();

        return [
            Actions\Action::make('imprimir_ticket')
                ->label('Imprimir Ticket')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->url(fn () => route('receipts.orders.print', [
                    'tenant' => $tenant,
                    'order' => $this->getRecord(),
                ]))
                ->openUrlInNewTab(),
            Actions\Action::make('descargar_pdf')
                ->label('Descargar PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('receipts.orders.pdf', [
                    'tenant' => $tenant,
                    'order' => $this->getRecord(),
                ]))
                ->openUrlInNewTab(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
        $this->refreshFormData(['subtotal', 'tax', 'total']);
    }
}
