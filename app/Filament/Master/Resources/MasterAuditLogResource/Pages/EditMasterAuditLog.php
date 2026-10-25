<?php

namespace App\Filament\Master\Resources\MasterAuditLogResource\Pages;

use App\Filament\Master\Resources\MasterAuditLogResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMasterAuditLog extends EditRecord
{
    protected static string $resource = MasterAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
