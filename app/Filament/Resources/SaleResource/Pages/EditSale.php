<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Services\SaleFinancialIntegrity;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        if (app(SaleFinancialIntegrity::class)->isFrozen($record) && array_key_exists('items', $data)) {
            throw ValidationException::withMessages([
                'items' => 'No se pueden modificar las líneas de una venta confirmada.',
            ]);
        }

        return $data;
    }

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
