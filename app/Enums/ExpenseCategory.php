<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Alquiler = 'alquiler';
    case Luz = 'luz';
    case Agua = 'agua';
    case Internet = 'internet';
    case Sueldos = 'sueldos';
    case Transporte = 'transporte';
    case Marketing = 'marketing';
    case Mantenimiento = 'mantenimiento';
    case Impuestos = 'impuestos';
    case Otros = 'otros';

    public function label(): string
    {
        return match ($this) {
            self::Alquiler => 'Alquiler',
            self::Luz => 'Luz / Electricidad',
            self::Agua => 'Agua',
            self::Internet => 'Internet',
            self::Sueldos => 'Sueldos',
            self::Transporte => 'Transporte / Fletes',
            self::Marketing => 'Marketing / Publicidad',
            self::Mantenimiento => 'Mantenimiento',
            self::Impuestos => 'Impuestos',
            self::Otros => 'Otros',
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
