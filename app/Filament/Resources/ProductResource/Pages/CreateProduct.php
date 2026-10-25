<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $imageUrl = trim((string) Arr::pull($data, 'image_url', ''));
        if ($imageUrl !== '') {
            $data['image'] = $imageUrl;
        }

        return $data;
    }
}
