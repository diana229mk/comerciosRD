<?php

namespace App\Filament\Resources\PrescriptionResource\Pages;

use App\Filament\Resources\PrescriptionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePrescription extends CreateRecord
{
    protected static string $resource = PrescriptionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['business_id']);
        $data['created_by'] = auth()->id();
        $business = filament()->getTenant();
        if (filled($data['image_path'] ?? null)) {
            abort_unless($business, 403);
            app(\App\Services\PrivateDocumentStorage::class)->assertStoredForBusiness(
                (string) $data['image_path'],
                'prescriptions',
                (string) $business->getKey(),
                'image_path',
            );
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
