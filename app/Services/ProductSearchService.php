<?php

namespace App\Services;

use App\Contracts\ProductSearch;
use App\Enums\BusinessType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ScaleMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Búsqueda unificada de productos para POS, autocomplete y escáner.
 *
 * Reutilizable desde cualquier página/widget:
 *   - Pos::searchSuggestions()
 *   - Escáneres de código de barras (findExact)
 *   - Recetas / kardex / traspasos, etc.
 *
 * La lógica aquí es la fuente de verdad; cada consumidor solo pasa el término
 * y el tipo de negocio (para búsquedas específicas: OEM en repuestos, etc.).
 */
class ProductSearchService implements ProductSearch
{
    public const DEFAULT_LIMIT = 8;

    /**
     * Sugerencias por texto parcial (autocomplete / typeahead).
     *
     * @return Collection<int, array{
     *     id:string, name:string, brand:?string, sku:?string, barcode:?string,
     *     price:float, stock:float, track_stock:bool, matched:string
     * }>
     */
    public function suggest(string $term, ?BusinessType $type = null, int $limit = self::DEFAULT_LIMIT): Collection
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) < 2) {
            return collect();
        }

        return ScaleMetrics::time('search.suggest', function () use ($term, $type, $limit): Collection {
            return $this->suggestQuery($term, $type, $limit);
        }, ['len' => mb_strlen($term)]);
    }

    /**
     * @return Collection<int, array{
     *     id:string, name:string, brand:?string, sku:?string, barcode:?string,
     *     price:float, stock:float, track_stock:bool, matched:string
     * }>
     */
    protected function suggestQuery(string $term, ?BusinessType $type, int $limit): Collection
    {
        $like = "%{$term}%";

        $products = Product::query()
            ->active()
            ->where(fn (Builder $q) => $this->applyTextFilter($q, $term, $like, $type))
            // Prioriza matches exactos por código; luego alfabético.
            ->orderByRaw(
                "CASE
                    WHEN barcode = ? THEN 0
                    WHEN sku = ? THEN 1
                    WHEN name LIKE ? THEN 2
                    ELSE 3
                END",
                [$term, $term, "{$term}%"]
            )
            ->orderBy('name')
            ->limit($limit)
            ->get();

        return $products->map(fn (Product $p) => [
            'id'          => (string) $p->id,
            'name'        => $p->name,
            'brand'       => $p->brand,
            'sku'         => $p->sku,
            'barcode'     => $p->barcode,
            'price'       => (float) $p->price,
            'stock'       => (float) $p->stock,
            'track_stock' => (bool) $p->track_stock,
            'matched'     => $this->matchedField($p, $term),
        ])->values();
    }

    /**
     * Coincidencia exacta por código de barra / SKU / código de proveedor.
     * Optimizado para escáneres: primero variante, luego producto.
     */
    public function findExact(string $code, ?BusinessType $type = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return ScaleMetrics::time('search.exact', function () use ($code, $type): ?array {
            return $this->findExactQuery($code, $type);
        });
    }

    /**
     * @return array{
     *     type:string, product_id:string, variant_id:?string, name:string,
     *     price:float, stock:float, barcode:?string, sku:?string
     * }|null
     */
    protected function findExactQuery(string $code, ?BusinessType $type): ?array
    {
        $variant = ProductVariant::query()
            ->where(fn (Builder $q) => $q->where('barcode', $code)->orWhere('sku', $code))
            ->whereHas('product', fn (Builder $q) => $q->active())
            ->first();

        if ($variant) {
            return [
                'type'       => 'variant',
                'product_id' => (string) $variant->product_id,
                'variant_id' => (string) $variant->id,
                'name'       => $variant->product?->name ?? '',
                'price'      => (float) $variant->price,
                'stock'      => (float) $variant->stock,
                'barcode'    => $variant->barcode,
                'sku'        => $variant->sku,
            ];
        }

        $product = Product::query()
            ->active()
            ->where(function (Builder $q) use ($code, $type) {
                $q->where('barcode', $code)
                    ->orWhere('sku', $code)
                    ->orWhere('supplier_code', $code)
                    ->orWhereJsonContains('search_aliases', $code);

                if ($type === BusinessType::Repuestos) {
                    $q->orWhere('oem_code', $code);
                }
            })
            ->first();

        if (! $product) {
            return null;
        }

        return [
            'type'       => 'product',
            'product_id' => (string) $product->id,
            'variant_id' => null,
            'name'       => $product->name,
            'price'      => (float) $product->price,
            'stock'      => (float) $product->stock,
            'barcode'    => $product->barcode,
            'sku'        => $product->sku,
        ];
    }

    /**
     * Aplica los filtros de texto compartidos por sugerencias y búsqueda amplia.
     */
    protected function applyTextFilter(Builder $q, string $term, string $like, ?BusinessType $type): void
    {
        $q->where('barcode', $term)
            ->orWhere('sku', $term)
            ->orWhere('supplier_code', $term)
            ->orWhere('name', 'like', $like)
            ->orWhere('brand', 'like', $like)
            ->orWhere('sku', 'like', $like)
            ->orWhere('supplier_code', 'like', $like)
            ->orWhereJsonContains('search_aliases', $term);

        if ($type === BusinessType::Repuestos) {
            $q->orWhere('oem_code', $term)
                ->orWhere('oem_code', 'like', $like)
                ->orWhere('alt_codes', 'like', $like)
                ->orWhere('part_brand', 'like', $like)
                ->orWhere('vehicle_make', 'like', $like)
                ->orWhere('vehicle_model', 'like', $like);
        }
    }

    protected function matchedField(Product $p, string $term): string
    {
        return match (true) {
            $p->barcode === $term       => 'barcode',
            $p->sku === $term           => 'sku',
            $p->supplier_code === $term => 'supplier_code',
            default                     => 'name',
        };
    }
}
