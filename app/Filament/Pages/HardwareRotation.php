<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use App\Services\InventoryRotationService;
use Filament\Pages\Page;

class HardwareRotation extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';
    protected static ?string $navigationLabel = 'Rotación de Inventario';
    protected static ?string $title = 'Rotación de Inventario';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 60;
    protected static string $view = 'filament.pages.hardware-rotation';
    protected static ?string $slug = 'rotacion-inventario';

    public int $days = 30;
    public string $filterClass = 'all';

    public function mount(): void
    {
        //
    }

    public function getRows()
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business) return collect();
        $rows = app(InventoryRotationService::class)->analyze($tenant, (int) $this->days);
        if ($this->filterClass !== 'all') {
            $rows = $rows->where('class', $this->filterClass)->values();
        }
        return $rows;
    }

    public function getSummary(): array
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business) return [];
        return app(InventoryRotationService::class)->summary($tenant, (int) $this->days);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore()) return false;
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
