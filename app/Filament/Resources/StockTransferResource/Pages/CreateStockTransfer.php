<?php
namespace App\Filament\Resources\StockTransferResource\Pages;
use App\Filament\Resources\StockTransferResource;
use App\Models\StockTransfer;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateStockTransfer extends CreateRecord {
    protected static string $resource = StockTransferResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = 'TRF-' . strtoupper(Str::random(8));
        $data['status'] = 'borrador';
        $data['created_by'] = auth()->id();
        return $data;
    }
}
