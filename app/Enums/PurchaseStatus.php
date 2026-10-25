<?php

namespace App\Enums;

enum PurchaseStatus: string
{
    case Borrador = 'borrador';
    case Recibida = 'recibida';
    case Anulada = 'anulada';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Recibida => 'Recibida',
            self::Anulada => 'Anulada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Recibida => 'success',
            self::Anulada => 'danger',
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
