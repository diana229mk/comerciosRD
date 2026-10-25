<?php

namespace App\Filament\Master\Resources\UserResource\Pages;

use App\Filament\Master\Resources\UserResource;
use App\Models\MasterAuditLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $auditOldValues = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function () {
                    MasterAuditLog::log(
                        'delete_user',
                        $this->record,
                        $this->record->only(['name', 'email', 'is_master_admin', 'is_active']),
                        []
                    );
                }),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditOldValues = $this->record->only(['name', 'email', 'is_master_admin', 'is_active']);
    }

    protected function afterSave(): void
    {
        MasterAuditLog::log(
            'update_user',
            $this->record,
            $this->auditOldValues ?? [],
            $this->record->only(['name', 'email', 'is_master_admin', 'is_active'])
        );
    }
}
