<?php

namespace App\Enums;

/**
 * Tipos de e-CF según nomenclatura DGII (República Dominicana).
 */
enum EcfType: string
{
    case CREDITO_FISCAL = '31';
    case CONSUMO = '32';
    case NOTA_DEBITO = '33';
    case NOTA_CREDITO = '34';
    case COMPRAS = '41';
    case GASTOS_MENORES = '43';
    case REGIMENES_ESPECIALES = '44';
    case GUBERNAMENTAL = '45';
    case EXPORTACIONES = '46';
    case PAGOS_EXTERIOR = '47';

    public function label(): string
    {
        return match ($this) {
            self::CREDITO_FISCAL => 'Factura de Crédito Fiscal',
            self::CONSUMO => 'Factura de Consumo',
            self::NOTA_DEBITO => 'Nota de Débito',
            self::NOTA_CREDITO => 'Nota de Crédito',
            self::COMPRAS => 'Comprobante de Compras',
            self::GASTOS_MENORES => 'Gastos Menores',
            self::REGIMENES_ESPECIALES => 'Regímenes Especiales',
            self::GUBERNAMENTAL => 'Gubernamental',
            self::EXPORTACIONES => 'Exportaciones',
            self::PAGOS_EXTERIOR => 'Pagos al Exterior',
        };
    }

    public function shortLabel(): string
    {
        return $this->label();
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [
                $type->value => $type->value.' - '.$type->label(),
            ])
            ->all();
    }
}
