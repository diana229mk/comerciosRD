<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * Sugerencia de reposición para ferretería:
 * productos con stock <= reorder_point (o min_stock si no hay reorder_point),
 * agrupados por proveedor preferido, con cantidad sugerida = reorder_quantity
 * (o max_stock - stock) y último costo conocido.
 */
class ReplenishmentService
{
    /**
     * @return Collection<int, array{
     *     product: Product,
     *     supplier: ?Supplier,
     *     suggested_qty: float,
     *     last_cost: ?float,
     * }>
     */
    public function suggest(string $businessId): Collection
    {
        $products = Product::query()
            ->where('business_id', $businessId)
            ->where('is_active', true)
            ->where('track_stock', true)
            ->where('is_kit', false)
            ->get()
            ->filter(function (Product $p) {
                $threshold = (float) ($p->reorder_point ?? $p->min_stock ?? 0);
                return $threshold > 0 && (float) $p->stock <= $threshold;
            });

        return $products->map(function (Product $p) {
            $suggestedQty = (float) ($p->reorder_quantity ?? 0);
            if ($suggestedQty <= 0 && $p->max_stock) {
                $suggestedQty = max(0.0, (float) $p->max_stock - (float) $p->stock);
            }
            if ($suggestedQty <= 0) {
                $suggestedQty = max(1.0, (float) ($p->reorder_point ?? $p->min_stock ?? 0) * 2);
            }

            // Proveedor preferido (marcado) o el más reciente
            $ps = ProductSupplier::query()
                ->where('product_id', $p->id)
                ->orderByDesc('is_preferred')
                ->orderByDesc('last_purchase_date')
                ->first();

            $supplier = $p->preferredSupplier ?? $ps?->supplier;

            return [
                'product'       => $p,
                'supplier'      => $supplier,
                'suggested_qty' => round($suggestedQty, 3),
                'last_cost'     => $ps?->last_cost !== null ? (float) $ps->last_cost : (float) $p->cost,
            ];
        })->values();
    }

    /**
     * Comparación de proveedores para un producto: devuelve todos los ProductSupplier
     * ordenados por último costo ascendente.
     */
    public function compareSuppliersFor(string $productId): Collection
    {
        return ProductSupplier::query()
            ->where('product_id', $productId)
            ->with('supplier')
            ->orderBy('last_cost')
            ->get();
    }
}
