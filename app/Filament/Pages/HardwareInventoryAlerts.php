<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use App\Services\InventoryAlertService;
use Filament\Pages\Page;

class HardwareInventoryAlerts extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationLabel = 'Alertas de Inventario';
    protected static ?string $title = 'Alertas de Inventario';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 55;
    protected static string $view = 'filament.pages.hardware-inventory-alerts';
    protected static ?string $slug = 'alertas-inventario';

    public array $low = [];
    public array $over = [];
    public array $dead = [];

    public function mount(): void
    {
        $tenant = filament()->getTenant();
        if ($tenant instanceof Business) {
            $s = app(InventoryAlertService::class)->summary($tenant->id);
            $this->low = $s['low']->all();
            $this->over = $s['over']->all();
            $this->dead = $s['dead']->all();
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore() || ! $tenant->hasModule('inventory_movements')) return false;
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
