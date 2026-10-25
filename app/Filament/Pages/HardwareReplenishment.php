<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use App\Services\ReplenishmentService;
use Filament\Pages\Page;

class HardwareReplenishment extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?string $navigationLabel = 'Reposición sugerida';
    protected static ?string $title = 'Reposición sugerida';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 50;
    protected static string $view = 'filament.pages.hardware-replenishment';
    protected static ?string $slug = 'reposicion-sugerida';

    public array $suggestions = [];

    public function mount(): void
    {
        $tenant = filament()->getTenant();
        if ($tenant instanceof Business) {
            $this->suggestions = app(ReplenishmentService::class)->suggest($tenant->id)->all();
        }
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore() || ! $tenant->hasModule('purchases')) return false;
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
