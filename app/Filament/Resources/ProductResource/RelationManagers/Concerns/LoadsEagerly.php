<?php

namespace App\Filament\Resources\ProductResource\RelationManagers\Concerns;

/**
 * Livewire /livewire/update no pasa por IdentifyTenant.
 * Los RM lazy piden getModel() con query nula y explotan.
 */
trait LoadsEagerly
{
    public static function isLazy(): bool
    {
        return false;
    }
}
