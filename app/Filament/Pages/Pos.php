<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Models\Business;
use App\Models\User;
use App\Services\PosSessionService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;

/**
 * POS embebido en Filament. El carrito NO vive en Livewire:
 * la vista monta el mismo terminal JSON que /caja/{slug}.
 */
class Pos extends Page
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'sales';

    protected static string $viewPermission = 'view_sales';

    protected static string $createPermission = 'create_sales';

    protected static bool $cashierNavigation = true;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'POS';

    protected static ?string $title = 'Punto de Venta';

    protected static ?string $navigationGroup = 'Ventas';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.pos';

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::Full;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $config = $tenant instanceof Business
            ? app(PosSessionService::class)->clientConfig($tenant)
            : null;

        return [
            'posConfig' => $config,
            'embedded' => true,
        ];
    }

    public function getHeading(): string|Htmlable
    {
        $name = Filament::getTenant()?->name ?? 'ComerciosRD';

        return 'POS';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $type = Filament::getTenant()?->business_type ?? null;

        return match ($type) {
            'colmado' => 'Escanea, agrega y cobra',
            'farmacia' => 'Busca medicamento y cobra',
            'repuestos' => 'OEM, vehículo o código · agrega a la cuenta',
            default => 'Busca, agrega y cobra',
        };
    }

    public static function canAccess(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'manager', 'cashier'])) {
            return false;
        }

        return $user->canPerform('create_sales');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }
}
