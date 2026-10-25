<?php

namespace App\Filament\Concerns;

use App\Models\Business;

/**
 * canView() para widgets del Dashboard según enabled_modules del tenant.
 *
 * En el widget:
 *   protected static array $moduleAnyOf = ['orders'];
 *   // o varios: ['sales', 'orders']  → basta con uno
 */
trait RequiresBusinessModules
{
    public static function canView(): bool
    {
        $tenant = Business::currentTenant();

        if (! $tenant instanceof Business) {
            return false;
        }

        $modules = static::moduleAnyOf();

        if ($modules === []) {
            return true;
        }

        return $tenant->hasAnyModule(...$modules);
    }

    /**
     * @return list<string>
     */
    protected static function moduleAnyOf(): array
    {
        if (property_exists(static::class, 'moduleAnyOf')) {
            /** @var list<string> $mods */
            $mods = static::$moduleAnyOf;

            return array_values($mods);
        }

        if (property_exists(static::class, 'businessModule')) {
            return [(string) static::$businessModule];
        }

        return [];
    }
}
