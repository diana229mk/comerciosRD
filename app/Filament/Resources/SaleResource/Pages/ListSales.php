<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Pages\Pos;
use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nueva venta')
                ->icon('heroicon-o-plus'),
            Actions\Action::make('pos')
                ->label('Abrir POS rápido')
                ->icon('heroicon-o-shopping-cart')
                ->url(Pos::getUrl())
                ->color('primary'),
        ];
    }
}
