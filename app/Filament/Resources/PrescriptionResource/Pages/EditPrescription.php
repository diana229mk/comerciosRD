<?php

namespace App\Filament\Resources\PrescriptionResource\Pages;

use App\Filament\Resources\PrescriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPrescription extends EditRecord
{
    protected static string $resource = PrescriptionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $imagePath = $data['image_path'] ?? null;
        $record = $this->getRecord();
        if (filled($imagePath) && $imagePath !== $record->image_path) {
            app(\App\Services\PrivateDocumentStorage::class)->assertStoredForBusiness(
                (string) $imagePath,
                'prescriptions',
                (string) $record->business_id,
                'image_path',
            );
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
        ];
    }
}
