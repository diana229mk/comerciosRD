<?php

namespace App\Filament\Resources\CashSessionResource\Pages;

use App\Filament\Resources\CashSessionResource;
use App\Services\CashSessionService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditCashSession extends EditRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record->isOpen()) {
            throw ValidationException::withMessages([
                'closing_amount' => 'Esta sesión ya está cerrada.',
            ]);
        }

        $closing = $data['closing_amount'] ?? null;
        if ($closing === null || $closing === '') {
            throw ValidationException::withMessages([
                'closing_amount' => 'Indica el monto real contado.',
            ]);
        }

        try {
            return app(CashSessionService::class)->close(
                $record,
                (float) $closing,
                $data['closing_notes'] ?? null,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'closing_amount' => $e->getMessage(),
            ]);
        }
    }

    protected function afterSave(): void
    {
        $difference = (float) ($this->record->difference ?? 0);

        if ($difference > 0) {
            Notification::make()
                ->title('Caja cerrada con sobrante')
                ->body('Sobrante: RD$ '.number_format($difference, 2))
                ->success()
                ->send();
        } elseif ($difference < 0) {
            Notification::make()
                ->title('Caja cerrada con faltante')
                ->body('Faltante: RD$ '.number_format(abs($difference), 2))
                ->danger()
                ->send();
        } else {
            Notification::make()
                ->title('Caja cerrada correctamente')
                ->body('No hubo diferencias (cuadre exacto).')
                ->success()
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        if ($this->record->isClosed()) {
            $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
        }
    }
}
