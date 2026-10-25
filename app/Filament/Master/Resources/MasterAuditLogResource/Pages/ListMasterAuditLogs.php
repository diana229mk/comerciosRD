<?php

namespace App\Filament\Master\Resources\MasterAuditLogResource\Pages;

use App\Filament\Master\Resources\MasterAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMasterAuditLogs extends ListRecords
{
    protected static string $resource = MasterAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
