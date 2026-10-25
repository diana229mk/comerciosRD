<?php

namespace App\Filament\Resources\EcfSequenceResource\Pages;

use App\Filament\Resources\EcfSequenceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEcfSequences extends ListRecords
{
    protected static string $resource = EcfSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nueva Secuencia'),
        ];
    }
}
