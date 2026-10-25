<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Support\DashboardPeriod;
use App\Support\DashboardWidgetRegistry;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * Dashboard del dueño — saludo dinámico y widgets prioritarios
 * derivados del catálogo central (DashboardWidgetRegistry).
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = -2;

    protected function dashboardScopeLabel(?Business $business): string
    {
        if (! $business) {
            return 'Vista completa';
        }

        $branchId = session(\App\Services\AuthContextResolver::SESSION_BRANCH_ID);
        if (! is_string($branchId) || $branchId === '') {
            return 'Dashboard completo (todas las sucursales)';
        }

        $name = \App\Models\Branch::query()
            ->where('business_id', $business->getKey())
            ->whereKey($branchId)
            ->value('name');

        return $name ? "Por local: {$name}" : 'Por local';
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        // Solo dirección ve el tablero financiero. Preventa, cajero e inventario no.
        return \App\Support\DashboardAccess::allowed();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        if (! $user instanceof \App\Models\User || ! $tenant instanceof Business) {
            return false;
        }

        return $user->canAccessTenant($tenant);
    }

    public function mount(): void
    {
        if (\App\Support\DashboardAccess::allowed()) {
            return;
        }

        $user = auth()->user();
        $tenant = Filament::getTenant();
        if ($user instanceof \App\Models\User && $tenant instanceof Business) {
            $url = app(\App\Services\AuthContextResolver::class)->panelDestination($user, $tenant);
            if (is_string($url) && $url !== '') {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(
                    new \Illuminate\Http\RedirectResponse($url)
                );
            }
        }
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $greeting = $this->greetingForNow();
        $name = $this->ownerFirstName();

        if ($name !== null) {
            return "{$greeting}, {$name} 👋";
        }

        $business = Filament::getTenant();
        if ($business instanceof Business) {
            return "{$greeting} · {$business->name}";
        }

        return $greeting;
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        $business = Filament::getTenant();
        $type = $business instanceof Business ? $business->business_type : 'general';

        $hint = match ($type) {
            'colmado' => 'Ventas del día, fiado y caja',
            'farmacia' => 'Ventas, vencimientos y cobros',
            'ferreteria' => 'Ventas, stock y cobros',
            'cafeteria' => 'Órdenes activas y productos top',
            'tienda' => 'Ventas, catálogo y cobros',
            'distribuidora', 'almacen' => 'Ventas, mayoreo y stock',
            default => 'Resumen en tiempo real',
        };

        $businessLabel = $business instanceof Business
            ? "{$business->name} · {$business->type_label}"
            : null;

        $date = now()->translatedFormat('l d \d\e F Y');
        $scope = $this->dashboardScopeLabel($business);
        $period = DashboardPeriod::fromFilters($this->filters)['label'];

        return trim(($businessLabel ? "{$businessLabel} — " : '')."{$hint} · {$scope} · {$period} · {$date}");
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('preset')
                ->label('Período')
                ->options([
                    'today' => 'Hoy',
                    'week' => 'Esta semana',
                    'month' => 'Este mes',
                    'custom' => 'Rango de fechas',
                ])
                ->default('month')
                ->native(false)
                ->live(),
            Forms\Components\DatePicker::make('date_from')
                ->label('Desde')
                ->native(false)
                ->visible(fn (Forms\Get $get): bool => $get('preset') === 'custom')
                ->live(),
            Forms\Components\DatePicker::make('date_to')
                ->label('Hasta')
                ->native(false)
                ->visible(fn (Forms\Get $get): bool => $get('preset') === 'custom')
                ->live(),
        ])->columns(3);
    }

    /**
     * Fuente única de verdad: el catálogo del registry decide el orden y set
     * de widgets por tipo de negocio. Cada widget conserva su propio canView()
     * (módulos + preferencias del dueño).
     *
     * @return array<class-string<\Filament\Widgets\Widget>|\Filament\Widgets\WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $type = Filament::getTenant()?->type;

        return DashboardWidgetRegistry::dashboardWidgetsFor($type);
    }

    /**
     * @return int|string|array<string, int|string|null>
     */
    public function getColumns(): int|string|array
    {
        if (Filament::getTenant()?->isRetailStore()) {
            return [
                'default' => 1,
                'sm' => 2,
                'xl' => 3,
            ];
        }

        return [
            'default' => 1,
            'sm' => 2,
            'xl' => 2,
        ];
    }

    /**
     * Saludo según la hora local del negocio (fallback a UTC).
     */
    protected function greetingForNow(): string
    {
        $hour = (int) now()->format('G');

        return match (true) {
            $hour < 12 => 'Buenos días',
            $hour < 19 => 'Buenas tardes',
            default    => 'Buenas noches',
        };
    }

    /**
     * Devuelve el primer nombre del usuario autenticado, si existe.
     */
    protected function ownerFirstName(): ?string
    {
        $user = auth()->user();
        $name = $user?->name;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return \Illuminate\Support\Str::of($name)->trim()->explode(' ')->first();
    }
}
