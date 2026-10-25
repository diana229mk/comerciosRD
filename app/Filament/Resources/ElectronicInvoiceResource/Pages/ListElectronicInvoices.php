<?php

namespace App\Filament\Resources\ElectronicInvoiceResource\Pages;

use App\Filament\Resources\ElectronicInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListElectronicInvoices extends ListRecords
{
    protected static string $resource = ElectronicInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
