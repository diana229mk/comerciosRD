<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('imprimir_ticket')
                ->label('Imprimir ticket')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => SaleResource::saleIsCompleted($this->getRecord()))
                ->url(fn (): string => SaleResource::getUrl('ticket', ['record' => $this->getRecord()]))
                ->openUrlInNewTab(),

            Actions\DeleteAction::make(),
        ];
    }
}
