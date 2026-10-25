<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\User;
use App\Services\MenuCatalogService;
use App\Services\QrCodeService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class QrMenu extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Menú QR';

    protected static ?string $title = 'Menú QR';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.qr-menu';

    protected static ?string $slug = 'menu-qr';

    public static function getNavigationGroup(): ?string
    {
        return Filament::getTenant()?->isCafeteria() ? 'Cocina' : 'Ventas';
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }
        if (! $tenant->isCafeteria() || ! $tenant->hasModule('products')) {
            return false;
        }

        return $user->hasRoleInBusiness(['owner', 'admin']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return [];
        }

        $menus = app(MenuCatalogService::class);
        $catalog = $menus->forBusiness($tenant);
        $url = $catalog['url'];

        return [
            'menuUrl' => $url,
            'qrSvg' => app(QrCodeService::class)->svg($url, 280),
            'posterUrl' => route('menu.poster', ['slug' => $tenant->slug]),
            'downloadUrl' => route('menu.qr', ['slug' => $tenant->slug]),
            'itemCount' => $catalog['categories']->sum(fn ($c) => $c['items']->count()),
            'categoryCount' => $catalog['categories']->count(),
        ];
    }
}
