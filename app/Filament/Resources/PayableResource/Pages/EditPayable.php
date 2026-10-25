<?php

namespace App\Filament\Resources\PayableResource\Pages;

use App\Filament\Resources\PayableResource;
use App\Models\Payable;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPayable extends EditRecord
{
    protected static string $resource = PayableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('registrar_pago')
                ->label('Registrar Pago')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->modalHeading('Registrar pago y factura')
                ->modalDescription('Ingresa el monto del pago y adjunta la factura escaneada (PDF o imagen).')
                ->modalWidth('lg')
                ->visible(fn (): bool => ! $this->getRecord()->isPaid())
                ->form(fn (): array => PayableResource::registerPaymentFormSchema($this->getRecord()))
                ->action(function (array $data): void {
                    /** @var Payable $record */
                    $record = $this->getRecord();

                    try {
                        PayableResource::processRegisterPayment($record, $data);

                        $this->refreshFormData([
                            'paid_amount',
                            'balance',
                            'status',
                            'invoice_file',
                        ]);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo registrar el pago')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
