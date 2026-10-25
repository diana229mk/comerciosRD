<?php

namespace App\Filament\Resources\ReceivableResource\Pages;

use App\Filament\Resources\ReceivableResource;
use App\Models\Receivable;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditReceivable extends EditRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('registrar_cobro')
                ->label('Registrar Cobro')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->modalHeading('Registrar cobro')
                ->modalWidth('lg')
                ->visible(fn (): bool => ! $this->getRecord()->isPaid())
                ->form(fn (): array => ReceivableResource::registerCollectionFormSchema($this->getRecord()))
                ->action(function (array $data): void {
                    /** @var Receivable $record */
                    $record = $this->getRecord();

                    try {
                        ReceivableResource::processRegisterCollection($record, $data);

                        $this->refreshFormData([
                            'paid_amount',
                            'balance',
                            'status',
                        ]);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo registrar el cobro')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
