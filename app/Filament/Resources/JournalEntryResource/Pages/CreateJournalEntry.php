<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use App\Models\JournalEntry;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateJournalEntry extends CreateRecord
{
    protected static string $resource = JournalEntryResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = JournalEntry::STATUS_DRAFT;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals();
        $this->assertLinesValid();
    }

    protected function assertLinesValid(): void
    {
        $this->record->load('lines');
        if ($this->record->lines->count() < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Agrega al menos 2 líneas contables.',
            ]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
