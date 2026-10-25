<?php

namespace App\Filament\Master\Resources\MasterAuditLogResource\Pages;

use App\Filament\Master\Resources\MasterAuditLogResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMasterAuditLog extends CreateRecord
{
    protected static string $resource = MasterAuditLogResource::class;
}
