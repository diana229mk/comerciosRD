<?php

namespace App\Enums;

enum InventoryBehavior: string
{
    /** Descuenta stock al vender (productos terminados / reventa) */
    case DeductOnSale = 'deduct_on_sale';

    /** No descuenta al vender (materias primas, recetas, servicios) */
    case DoNotDeduct = 'do_not_deduct';

    public function label(): string
    {
        return match ($this) {
            self::DeductOnSale => 'Descontar al vender',
            self::DoNotDeduct => 'No descontar al vender',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DeductOnSale => 'Ideal para colmados, farmacias, ferreterías y distribuidoras (producto terminado).',
            self::DoNotDeduct => 'Ideal para materias primas en cafeterías/restaurantes (lechuga, pan, carne).',
        };
    }

    public function shouldDeductOnSale(): bool
    {
        return $this === self::DeductOnSale;
    }

    /**
     * Default según tipo de negocio (puede sobrescribirse por producto).
     */
    public static function defaultForBusinessType(?BusinessType $type): self
    {
        return match ($type) {
            BusinessType::Cafeteria => self::DoNotDeduct,
            default => self::DeductOnSale,
        };
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
}
