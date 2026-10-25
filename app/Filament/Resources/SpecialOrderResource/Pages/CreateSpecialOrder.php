<?php
namespace App\Filament\Resources\SpecialOrderResource\Pages;
use App\Filament\Resources\SpecialOrderResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateSpecialOrder extends CreateRecord {
    protected static string $resource = SpecialOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = 'PE-' . strtoupper(Str::random(8));
        $data['created_by'] = auth()->id();
        return $data;
    }
}
