<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use App\Models\Branch;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Si esta pasa a principal, desmarca las demás del mismo negocio.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Branch $record */
        $record = parent::handleRecordUpdate($record, $data);

        if ((bool) $record->is_main === true) {
            $tenant = Filament::getTenant();
            if ($tenant) {
                Branch::query()
                    ->where('business_id', $tenant->getKey())
                    ->where('id', '!=', $record->getKey())
                    ->where('is_main', true)
                    ->update(['is_main' => false]);
            }
        }

        return $record;
    }
}
