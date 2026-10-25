<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSubstitute;
use Illuminate\Support\Collection;

/**
 * Sustitutos / equivalentes: cuando un producto no tiene stock suficiente,
 * sugiere alternativas configuradas (ordenadas por priority ascendente).
 */
class SubstituteService
{
    /**
     * Devuelve productos sustitutos con stock disponible.
     * @return Collection<int, Product>
     */
    public function forProduct(Product $product, float $requiredQty = 1.0): Collection
    {
        $rows = ProductSubstitute::query()
            ->where('product_id', $product->id)
            ->orderBy('priority')
            ->with('substitute')
            ->get();

        return $rows->map(fn ($r) => $r->substitute)
            ->filter(fn (?Product $p) => $p && $p->is_active && (float) $p->stock >= $requiredQty)
            ->values();
    }

    /**
     * ¿El producto tiene stock suficiente? Si no, adjunta lista de sustitutos.
     * Retorna: ['ok' => bool, 'shortfall' => float, 'substitutes' => Collection]
     */
    public function check(Product $product, float $requiredQty): array
    {
        $stock = (float) $product->stock;
        if ($stock >= $requiredQty) {
            return ['ok' => true, 'shortfall' => 0.0, 'substitutes' => collect()];
        }
        return [
            'ok' => false,
            'shortfall' => round($requiredQty - $stock, 3),
            'substitutes' => $this->forProduct($product, $requiredQty),
        ];
    }
}
