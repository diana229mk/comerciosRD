<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case ReturnIn = 'return_in';
    case ReturnOut = 'return_out';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Compra (entrada)',
            self::Sale => 'Venta (salida)',
            self::AdjustmentIn => 'Ajuste de entrada',
            self::AdjustmentOut => 'Ajuste de salida',
            self::ReturnIn => 'Devolución de cliente',
            self::ReturnOut => 'Devolución a proveedor',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Purchase, self::AdjustmentIn, self::ReturnIn => 'success',
            self::Sale, self::AdjustmentOut, self::ReturnOut => 'danger',
        };
    }

    /**
     * ¿Este tipo incrementa el stock?
     */
    public function isInbound(): bool
    {
        return in_array($this, [
            self::Purchase,
            self::AdjustmentIn,
            self::ReturnIn,
        ], true);
    }

    /**
     * Multiplicador de cantidad: +1 entrada, -1 salida.
     */
    public function stockSign(): int
    {
        return $this->isInbound() ? 1 : -1;
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

    /**
     * Tipos que el dueño puede usar en ajustes manuales.
     *
     * @return array<string, string>
     */
    public static function adjustmentOptions(): array
    {
        return [
            self::AdjustmentIn->value => self::AdjustmentIn->label(),
            self::AdjustmentOut->value => self::AdjustmentOut->label(),
            self::Purchase->value => self::Purchase->label(),
            self::ReturnIn->value => self::ReturnIn->label(),
            self::ReturnOut->value => self::ReturnOut->label(),
        ];
    }
}
