<?php

namespace App\Filament\Resources\ProductSeasonResource\Pages;

use App\Filament\Resources\ProductSeasonResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProductSeason extends EditRecord
{
    protected static string $resource = ProductSeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
