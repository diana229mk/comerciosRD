<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pendiente = 'pendiente';
    case EnPreparacion = 'en_preparacion';
    case Lista = 'lista';
    case Completada = 'completada';
    case Cancelada = 'cancelada';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnPreparacion => 'En preparación',
            self::Lista => 'Lista',
            self::Completada => 'Completada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'warning',
            self::EnPreparacion => 'info',
            self::Lista => 'success',
            self::Completada => 'gray',
            self::Cancelada => 'danger',
        };
    }

    /**
     * Estados abiertos (cuentan como pendientes en el dashboard).
     *
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Pendiente, self::EnPreparacion, self::Lista];
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
