<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Efectivo = 'efectivo';
    case Tarjeta = 'tarjeta';
    case Transferencia = 'transferencia';
    case Mixto = 'mixto';
    case Credito = 'credito';
    case Cheque = 'cheque';

    // Legacy (compat con datos/POS antiguos)
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Mixed = 'mixed';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Efectivo, self::Cash => 'Efectivo',
            self::Tarjeta, self::Card => 'Tarjeta',
            self::Transferencia, self::Transfer => 'Transferencia',
            self::Mixto, self::Mixed => 'Mixto',
            self::Credito, self::Credit => 'Crédito',
            self::Cheque => 'Cheque',
        };
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::Credito, self::Credit], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Efectivo->value => 'Efectivo',
            self::Tarjeta->value => 'Tarjeta',
            self::Transferencia->value => 'Transferencia',
            self::Mixto->value => 'Mixto',
            self::Credito->value => 'Crédito',
            self::Cheque->value => 'Cheque',
        ];
    }

    /**
     * Opciones de cobro del POS / ventas según giro del negocio.
     *
     * @return array<string, string>
     */
    public static function optionsForBusinessType(?BusinessType $type): array
    {
        $key = $type?->value ?? 'general';

        $options = [
            self::Efectivo->value => 'Efectivo',
            self::Tarjeta->value => 'Tarjeta',
        ];

        // Fiado / crédito solo en estos giros
        if (in_array($key, ['colmado', 'distribuidora', 'ferreteria', 'repuestos', 'tienda', 'almacen'], true)) {
            $options[self::Credito->value] = match ($key) {
                'colmado' => 'Fiado',
                'distribuidora', 'almacen' => 'Crédito / Mayoreo',
                'repuestos' => 'Crédito Taller / Cliente',
                default => 'Crédito',
            };
        }

        // Transferencia útil en ferretería, repuestos, distribuidora y farmacia
        if (in_array($key, ['ferreteria', 'repuestos', 'distribuidora', 'farmacia', 'almacen'], true)) {
            $options[self::Transferencia->value] = 'Transferencia';
        }

        // En general/cafetería: transferencia opcional simple
        if (in_array($key, ['general', 'cafeteria', 'tienda'], true)) {
            $options[self::Transferencia->value] = 'Transferencia';
        }

        return $options;
    }

    public static function normalize(?string $value): self
    {
        $value = $value ?: 'efectivo';

        return match ($value) {
            'cash' => self::Efectivo,
            'card' => self::Tarjeta,
            'transfer' => self::Transferencia,
            'mixed' => self::Mixto,
            'credit', 'fiado' => self::Credito,
            default => self::tryFrom($value) ?? self::Efectivo,
        };
    }

    public static function isCreditValue(mixed $value): bool
    {
        if ($value instanceof self) {
            return $value->isCredit();
        }

        return self::normalize(is_string($value) ? $value : null)->isCredit();
    }
}
