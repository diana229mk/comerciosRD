<?php

namespace App\Enums;

enum PurchasePaymentStatus: string
{
    case Pendiente = 'pendiente';
    case Parcial = 'parcial';
    case Pagada = 'pagada';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente (a crédito)',
            self::Parcial => 'Pago parcial',
            self::Pagada => 'Pagada (contado)',
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
