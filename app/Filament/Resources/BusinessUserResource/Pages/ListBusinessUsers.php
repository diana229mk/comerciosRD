<?php

namespace App\Filament\Resources\BusinessUserResource\Pages;

use App\Filament\Resources\BusinessUserResource;
use App\Services\SeatLimitService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListBusinessUsers extends ListRecords
{
    protected static string $resource = BusinessUserResource::class;

    public function getSubheading(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return null;
        }

        $snap = app(SeatLimitService::class)->snapshot($tenant);

        return sprintf(
            'Plan %s · %d de %d usuarios · RD$ %s/mes',
            $snap['plan']->label(),
            $snap['used'],
            $snap['max'],
            number_format($snap['monthly'], 0)
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Nuevo usuario')
                ->icon('heroicon-o-plus'),
        ];
    }
}
