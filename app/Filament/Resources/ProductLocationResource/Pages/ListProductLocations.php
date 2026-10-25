<?php
namespace App\Filament\Resources\ProductLocationResource\Pages;
use App\Filament\Resources\ProductLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
class ListProductLocations extends ListRecords { protected static string $resource = ProductLocationResource::class;
protected function getHeaderActions(): array { return [Actions\CreateAction::make()]; }
}
