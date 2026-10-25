<?php

namespace App\Filament\Resources\PayableResource\Pages;

use App\Filament\Resources\PayableResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePayable extends CreateRecord
{
    protected static string $resource = PayableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['business_id']);
        $data['created_by'] = auth()->id();
        $data['paid_amount'] = $data['paid_amount'] ?? 0;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
