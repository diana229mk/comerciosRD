<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductKitItem;

/**
 * Expande un producto tipo kit en sus componentes.
 * Retorna arreglo de líneas: [product_id, variant_id, quantity]
 * que consumen del stock real de cada componente.
 */
class KitService
{
    /**
     * Expande un kit: para cada componente, cantidad componente = cantidad_kit * quantity_componente.
     */
    public function expand(Product $kitProduct, float $kitQuantity): array
    {
        if (! $kitProduct->is_kit) {
            return [];
        }

        $components = ProductKitItem::query()
            ->where('kit_product_id', $kitProduct->id)
            ->with('component')
            ->get();

        $lines = [];
        foreach ($components as $c) {
            $lines[] = [
                'product_id'  => $c->component_product_id,
                'variant_id'  => $c->component_variant_id,
                'quantity'    => (float) $c->quantity * $kitQuantity,
                'product_name'=> $c->component?->name,
            ];
        }
        return $lines;
    }
}
