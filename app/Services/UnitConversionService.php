<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;

/**
 * Convierte cantidades entre unidades múltiples (unidad, caja, metro, kg, etc.).
 * La unidad base tiene factor 1. Cualquier otra unidad multiplica su factor por la cantidad
 * para obtener el equivalente en unidad base (que es la que descuenta stock).
 */
class UnitConversionService
{
    /**
     * Resuelve un ProductUnit dado unit_id, unit_code o null (retorna base).
     */
    public function resolveUnit(Product $product, ?string $unitId = null, ?string $unitCode = null): ?ProductUnit
    {
        if ($unitId) {
            return ProductUnit::query()
                ->where('product_id', $product->id)
                ->where('id', $unitId)
                ->where('is_active', true)
                ->first();
        }
        if ($unitCode) {
            return ProductUnit::query()
                ->where('product_id', $product->id)
                ->where('unit_code', $unitCode)
                ->where('is_active', true)
                ->first();
        }
        return ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('is_base', true)
            ->first();
    }

    /**
     * Devuelve la cantidad convertida a unidad base.
     */
    public function toBase(float $quantity, ?ProductUnit $unit): float
    {
        if (! $unit) {
            return $quantity;
        }
        return $quantity * (float) $unit->factor;
    }

    /**
     * Factor efectivo de una unidad (o 1 si null).
     */
    public function factor(?ProductUnit $unit): float
    {
        return $unit ? (float) $unit->factor : 1.0;
    }
}
