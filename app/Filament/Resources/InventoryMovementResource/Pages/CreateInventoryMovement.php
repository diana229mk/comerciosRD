<?php

namespace App\Filament\Resources\InventoryMovementResource\Pages;

use App\Filament\Resources\InventoryMovementResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateInventoryMovement extends CreateRecord
{
    protected static string $resource = InventoryMovementResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $product = Product::query()->findOrFail($data['product_id']);

        try {
            return app(InventoryService::class)->move($product, [
                'type' => $data['type'],
                'quantity' => abs((float) $data['quantity']),
                'reason' => $data['reason'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
                'created_by' => auth()->id(),
            ]);
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo registrar el movimiento')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();

            // Nunca llega aquí; satisfacemos el tipo de retorno
            return new InventoryMovement;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Movimiento de inventario registrado';
    }
}
