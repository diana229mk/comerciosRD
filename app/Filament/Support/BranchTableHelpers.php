<?php

namespace App\Filament\Support;

use App\Models\Branch;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Helpers de UI para mostrar/filtrar por sucursal en tablas de Filament.
 *
 * FASE S2.5 — Se añaden a resources operativos (Sale, CashSession, Expense,
 * Order, Purchase) sin duplicar código.
 *
 *  - Columna: toggleable, oculta por defecto (no invade el layout actual).
 *  - Filtro: SelectFilter con las sucursales activas del tenant.
 *  - Ambos se ocultan si el negocio no tiene sucursales creadas.
 */
class BranchTableHelpers
{
    public static function column(): TextColumn
    {
        return TextColumn::make('branch.name')
            ->label('Sucursal')
            ->placeholder('—')
            ->toggleable(isToggledHiddenByDefault: true)
            ->sortable()
            ->visible(static::hasBranches());
    }

    public static function filter(): SelectFilter
    {
        return SelectFilter::make('branch_id')
            ->label('Sucursal')
            ->options(fn (): array => static::branchOptions())
            ->placeholder('Todas las sucursales')
            ->visible(static::hasBranches());
    }

    /**
     * @return array<string, string>
     */
    protected static function branchOptions(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }

        return Branch::query()
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function hasBranches(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return false;
        }

        return Branch::query()
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->exists();
    }
}
