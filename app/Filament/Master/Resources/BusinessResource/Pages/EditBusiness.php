<?php

namespace App\Filament\Master\Resources\BusinessResource\Pages;

use App\Filament\Master\Resources\BusinessResource;
use App\Models\MasterAuditLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBusiness extends EditRecord
{
    protected static string $resource = BusinessResource::class;

    /** @var array<string, mixed>|null */
    protected ?array $auditOldValues = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function () {
                    MasterAuditLog::log(
                        'delete_business',
                        $this->record,
                        $this->record->only(['name', 'slug', 'type', 'is_active']),
                        []
                    );
                }),
        ];
    }

    protected function beforeSave(): void
    {
        $this->auditOldValues = $this->record->only(['name', 'slug', 'type', 'is_active', 'owner_id', 'subscription_plan', 'extra_seats']);
    }

    protected function afterSave(): void
    {
        MasterAuditLog::log(
            'update_business',
            $this->record,
            $this->auditOldValues ?? [],
            $this->record->only(['name', 'slug', 'type', 'is_active', 'owner_id', 'subscription_plan', 'extra_seats'])
        );

        app(\App\Services\SubscriptionBillingService::class)->ensureCurrentInvoice($this->record);
    }
}
