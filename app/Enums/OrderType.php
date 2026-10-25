<?php

namespace App\Enums;

enum OrderType: string
{
    case PorNombre = 'por_nombre';
    case PorNumero = 'por_numero';

    public function label(): string
    {
        return match ($this) {
            self::PorNombre => 'Por nombre del cliente',
            self::PorNumero => 'Por número de orden',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
