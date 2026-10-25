<?php

namespace App\Support;

use RuntimeException;

final class EcfFeature
{
    public static function enabled(): bool
    {
        return (bool) config('ecf.enabled', false)
            && (bool) config('ecf.validation_approved', false);
    }

    public static function ensureEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('Las operaciones e-CF están temporalmente deshabilitadas.');
        }
    }
}
