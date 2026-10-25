<?php

namespace App\Filament\Resources\InventoryMovementResource\Pages;

use App\Filament\Resources\InventoryMovementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInventoryMovement extends ViewRecord
{
    protected static string $resource = InventoryMovementResource::class;

    protected function getHeaderActions(): array
    {
        return []; // sin editar/borrar
    }
}
