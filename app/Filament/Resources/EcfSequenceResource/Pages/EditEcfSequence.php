<?php

namespace App\Filament\Resources\EcfSequenceResource\Pages;

use App\Filament\Resources\EcfSequenceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEcfSequence extends EditRecord
{
    protected static string $resource = EcfSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
