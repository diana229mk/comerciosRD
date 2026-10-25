<?php

namespace App\Filament\Resources\CashSessionResource\Pages;

use App\Filament\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Services\CashSessionService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCashSession extends CreateRecord
{
    protected static string $resource = CashSessionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();

        $openSession = CashSession::query()
            ->where('status', 'open')
            ->where('user_id', auth()->id())
            ->exists();

        if ($openSession) {
            Notification::make()
                ->title('Ya tienes una caja abierta')
                ->body('Cierra tu turno antes de abrir otro. Otro cajero puede tener el suyo al mismo tiempo.')
                ->danger()
                ->send();

            $this->halt();
        }

        try {
            return app(CashSessionService::class)->open(
                (float) ($data['opening_amount'] ?? 0),
                $data['opening_notes'] ?? null,
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'opening_amount' => $e->getMessage(),
            ]);
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('Caja abierta')
            ->body('Turno iniciado. Las ventas en efectivo contarán para el arqueo.');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
