<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Parcial',
            self::Paid => 'Pagada',
            self::Overdue => 'Vencida',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Partial => 'info',
            self::Paid => 'success',
            self::Overdue => 'danger',
        };
    }

    /**
     * @return list<self>
     */
    public static function openStatuses(): array
    {
        return [self::Pending, self::Partial, self::Overdue];
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
