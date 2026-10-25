<?php

namespace App\Filament\Concerns;

use App\Models\Business;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Oculta recursos/páginas del panel Admin según:
 *  1) enabled_modules del tenant
 *  2) permisos de rol (User::canPerform) si se define $viewPermission
 */
trait ChecksBusinessModule
{
    /**
     * Clave del módulo: protected static string $businessModule = 'orders';
     * Permiso de lectura: protected static string $viewPermission = 'view_orders';
     * Permiso de creación: protected static ?string $createPermission = 'create_orders';
     * Permiso de edición: protected static ?string $editPermission = 'edit_orders';
     * Permiso de borrado: protected static ?string $deletePermission = null; // owner/admin vía canPerform true
     */

    /**
     * Si true, el rol cajero (puro) ve este ítem en el menú.
     * POS, Ventas, Caja y Clientes marcan true; el resto queda oculto.
     */
    // protected static bool $cashierNavigation = false;

    /**
     * Navegación: módulo del negocio + permiso de vista + filtro cajero.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        return static::cashierMaySeeInNavigation();
    }

    public static function canAccess(): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        return parent::canAccess();
    }

    /**
     * Cajero puro: solo recursos con $cashierNavigation = true.
     */
    protected static function cashierMaySeeInNavigation(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isStrictCashier()) {
            return true;
        }

        if (property_exists(static::class, 'cashierNavigation')) {
            return (bool) static::$cashierNavigation;
        }

        return false;
    }

    /**
     * Default: módulo habilitado + viewPermission (si está definido).
     * Los Resources pueden sobrescribir canViewAny/canCreate/canEdit/canDelete.
     */
    public static function canViewAny(): bool
    {
        if (! static::tenantMatchesGiro()) {
            return false;
        }

        return static::tenantHasModule() && static::userCanViewResource();
    }

    public static function getNavigationGroup(): ?string
    {
        $declared = property_exists(static::class, 'navigationGroup')
            ? static::$navigationGroup
            : parent::getNavigationGroup();

        if ($declared !== 'Ferretería') {
            return $declared;
        }

        $tenant = Filament::getTenant();
        if ($tenant instanceof Business && $tenant->isRetailStore()) {
            return 'Tienda';
        }
        if ($tenant instanceof Business && $tenant->isAutoPartsStore()) {
            return 'Inventario';
        }

        return 'Ferretería';
    }

    /**
     * Helper para Resources restrictivos: solo owner/admin + módulo.
     */
    public static function onlyOwnerOrAdmin(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->hasRoleInBusiness(['owner', 'admin']);
    }

    public static function canCreate(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $perm = static::permissionKey('createPermission');
        if ($perm === null || $perm === '') {
            return $user->hasRoleInBusiness(['owner', 'admin']);
        }

        return $user->canPerform($perm);
    }

    public static function canEdit($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $perm = static::permissionKey('editPermission');
        if ($perm === null || $perm === '') {
            return $user->hasRoleInBusiness(['owner', 'admin']);
        }

        return $user->canPerform($perm);
    }

    public static function canDelete($record): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $perm = static::permissionKey('deletePermission');
        if ($perm === null || $perm === '') {
            return $user->hasRoleInBusiness(['owner', 'admin']);
        }

        return $user->canPerform($perm);
    }

    public static function canDeleteAny(): bool
    {
        return static::canDelete(null);
    }

    /**
     * El módulo del plan no basta: un café no ve Ferretería ni Tienda
     * aunque Master haya marcado gift_cards / special_orders.
     */
    protected static function resolveTenant(): ?Business
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Business) {
            return $tenant;
        }
        if (app()->bound('currentBusiness')) {
            $bound = app('currentBusiness');
            if ($bound instanceof Business) {
                return $bound;
            }
        }

        return null;
    }

    protected static function tenantMatchesGiro(): bool
    {
        $tenant = static::resolveTenant();
        if (! $tenant instanceof Business) {
            return false;
        }

        if (property_exists(static::class, 'pharmacyOnly') && static::$pharmacyOnly) {
            return $tenant->isPharmacy();
        }
        if (property_exists(static::class, 'retailOnly') && static::$retailOnly) {
            return $tenant->isRetailStore();
        }
        if (property_exists(static::class, 'cafeteriaOnly') && static::$cafeteriaOnly) {
            return $tenant->isCafeteria();
        }
        if (property_exists(static::class, 'hardwareOnly') && static::$hardwareOnly) {
            return $tenant->isHardwareStore();
        }
        if (property_exists(static::class, 'advancedInventoryOnly') && static::$advancedInventoryOnly) {
            return $tenant->usesAdvancedInventory();
        }

        return true;
    }

    protected static function tenantHasModule(): bool
    {
        if (! static::tenantMatchesGiro()) {
            return false;
        }

        $module = static::businessModuleKey();

        if ($module === '') {
            return true;
        }

        $tenant = static::resolveTenant();

        if (! $tenant instanceof Business) {
            return false;
        }

        return $tenant->hasModule($module);
    }

    protected static function userCanViewResource(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $perm = static::permissionKey('viewPermission');
        if ($perm === null || $perm === '') {
            return true;
        }

        return $user->canPerform($perm);
    }

    protected static function businessModuleKey(): string
    {
        return property_exists(static::class, 'businessModule')
            ? (string) static::$businessModule
            : '';
    }

    protected static function permissionKey(string $property): ?string
    {
        if (! property_exists(static::class, $property)) {
            return null;
        }

        $value = static::${$property};

        return $value === null ? null : (string) $value;
    }
}
