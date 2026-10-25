<?php

namespace App\Filament\Concerns;

use App\Models\Business;
use App\Services\DashboardMetricsService;
use App\Support\DashboardWidgetRegistry;
use Filament\Facades\Filament;

trait InteractsWithTenantDashboard
{
    public function currentBusiness(): ?Business
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Business ? $tenant : null;
    }

    public function metrics(): ?DashboardMetricsService
    {
        return DashboardMetricsService::for($this->currentBusiness());
    }

    /**
     * ¿Este widget está habilitado por módulos + preferencias + tipo de negocio?
     */
    public static function canView(): bool
    {
        if (! \App\Support\DashboardAccess::allowed()) {
            return false;
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Business) {
            return false;
        }

        if (! static::tenantPassesModuleGate($tenant)) {
            return false;
        }

        $key = static::widgetKey();

        if ($key === null) {
            return true;
        }

        if (! DashboardWidgetRegistry::appliesToBusiness($key, $tenant->type)) {
            return false;
        }

        return $tenant->isDashboardWidgetEnabled($key);
    }

    /**
     * Módulos requeridos (cualquiera basta). Vacío = no filtra por módulo.
     * Override en cada widget, p.ej. return ['orders']; o ['sales', 'orders'].
     *
     * @return list<string>
     */
    protected static function requiredModules(): array
    {
        return [];
    }

    protected static function tenantPassesModuleGate(Business $tenant): bool
    {
        $modules = static::requiredModules();

        if ($modules === []) {
            return true;
        }

        return $tenant->hasAnyModule(...$modules);
    }

    /**
     * Clave en DashboardWidgetRegistry. Override en cada widget.
     */
    protected static function widgetKey(): ?string
    {
        return null;
    }
}
