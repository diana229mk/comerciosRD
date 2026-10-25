<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use App\Models\Branch;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    /**
     * Si esta va marcada como principal, desmarca cualquier otra del
     * mismo negocio. Sólo puede haber una principal por negocio.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var Branch $record */
        $record = parent::handleRecordCreation($data);

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
