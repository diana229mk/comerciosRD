<?php
namespace App\Filament\Resources\ProductSerialResource\Pages;
use App\Filament\Resources\ProductSerialResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListProductSerials extends ListRecords { protected static string $resource = ProductSerialResource::class;
protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
