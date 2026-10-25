<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // business_id lo inyecta BelongsToBusiness / Filament tenant
        unset($data['business_id']);

        // Totales se recalculan al guardar ítems; asegurar defaults
        $data['subtotal'] = $data['subtotal'] ?? 0;
        $data['tax'] = $data['tax'] ?? 0;
        $data['total'] = $data['total'] ?? 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        // Recalcular con los ítems ya guardados por el repeater relationship
        $this->record->recalculateTotals();
    }
}
