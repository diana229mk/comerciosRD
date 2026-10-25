<?php

namespace App\Enums;

enum BusinessType: string
{
    case Colmado = 'colmado';
    case Farmacia = 'farmacia';
    case Ferreteria = 'ferreteria';
    case Repuestos = 'repuestos';
    case Cafeteria = 'cafeteria';
    case Tienda = 'tienda';
    case Distribuidora = 'distribuidora';
    case Almacen = 'almacen';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Colmado => 'Colmado / Minimarket',
            self::Farmacia => 'Farmacia',
            self::Ferreteria => 'Ferretería',
            self::Repuestos => 'Repuestos / Autopartes',
            self::Cafeteria => 'Cafetería / Restaurante',
            self::Tienda => 'Tienda / Boutique',
            self::Distribuidora => 'Distribuidora / Mayorista',
            self::Almacen => 'Almacén',
            self::General => 'General / Otro',
        };
    }

    /**
     * ¿Suele usar crédito / fiado en el POS?
     */
    public function supportsCreditSales(): bool
    {
        return in_array($this, [
            self::Colmado,
            self::Distribuidora,
            self::Ferreteria,
            self::Repuestos,
            self::Tienda,
            self::Almacen,
        ], true);
    }

    /**
     * ¿El POS exige cliente al cobrar?
     */
    public function requiresCustomerOnSale(): bool
    {
        return in_array($this, [
            self::Distribuidora,
            self::Almacen,
        ], true);
    }

    /**
     * ¿Enfoca vencimientos de productos?
     */
    public function tracksExpiry(): bool
    {
        return $this === self::Farmacia;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }

    public static function normalizeKey(mixed $value): ?string
    {
        if ($value instanceof self) {
            return $value->value;
        }

        if (! is_string($value)) {
            return null;
        }

        $key = strtolower(trim($value));

        if ($key === '') {
            return null;
        }

        $normalized = match ($key) {
            'ffarmacia' => self::Farmacia->value,
            'repuesto', 'repuestos' => self::Repuestos->value,
            default => $key,
        };

        return self::tryFrom($normalized)?->value ?? $normalized;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $key = self::normalizeKey($value);

        return $key !== null ? self::tryFrom($key) : null;
    }
}
