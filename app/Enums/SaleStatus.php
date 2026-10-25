<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Completada = 'completada';
    case Anulada = 'anulada';

    /** @deprecated compat */
    case Completed = 'completed';

    /** @deprecated compat */
    case Cancelled = 'cancelled';

    /** @deprecated compat */
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Completada, self::Completed => 'Completada',
            self::Anulada, self::Cancelled, self::Refunded => 'Anulada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completada, self::Completed => 'success',
            self::Anulada, self::Cancelled, self::Refunded => 'danger',
        };
    }

    public function isCompleted(): bool
    {
        return in_array($this, [self::Completada, self::Completed], true);
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::Anulada, self::Cancelled, self::Refunded], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Completada->value => 'Completada',
            self::Anulada->value => 'Anulada',
        ];
    }
}
