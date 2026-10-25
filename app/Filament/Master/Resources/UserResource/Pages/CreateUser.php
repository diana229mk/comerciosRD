<?php

namespace App\Filament\Master\Resources\UserResource\Pages;

use App\Filament\Master\Resources\UserResource;
use App\Models\MasterAuditLog;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        MasterAuditLog::log(
            'create_user',
            $this->record,
            [],
            $this->record->only(['name', 'email', 'is_master_admin', 'is_active'])
        );
    }
}
