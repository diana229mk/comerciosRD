<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Services\PurchaseService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('marcar_recibida')
                ->label('Marcar como Recibida')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Marcar compra como recibida')
                ->modalDescription('Se actualizará el inventario y, si aplica, se creará una cuenta por pagar.')
                ->visible(fn (): bool => $this->record->isDraft())
                ->action(function (): void {
                    try {
                        app(PurchaseService::class)->receive($this->record);
                        Notification::make()
                            ->title('Compra marcada como recibida')
                            ->body('El inventario ha sido actualizado.')
                            ->success()
                            ->send();
                        $this->redirect($this->getResource()::getUrl('index'));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\DeleteAction::make()
                ->visible(fn (): bool => ! $this->record->isReceived()),
        ];
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if ($this->record->isReceived()) {
            $this->redirect($this->getResource()::getUrl('index'));
        }
    }

    protected function afterSave(): void
    {
        $this->record->recalculateTotals();
    }
}
