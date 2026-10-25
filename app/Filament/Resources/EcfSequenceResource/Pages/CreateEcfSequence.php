<?php

namespace App\Filament\Resources\EcfSequenceResource\Pages;

use App\Filament\Resources\EcfSequenceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEcfSequence extends CreateRecord
{
    protected static string $resource = EcfSequenceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['business_id']);
        $data['current_number'] = $data['current_number'] ?? 0;

        return $data;
    }
}
