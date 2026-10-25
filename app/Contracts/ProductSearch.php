<?php

namespace App\Contracts;

use App\Enums\BusinessType;
use Illuminate\Support\Collection;

/**
 * Puerto de búsqueda de productos (POS / autocomplete / escáner).
 *
 * Implementación actual: MySQL (App\Services\ProductSearchService).
 * A escala se cambia el binding en AppServiceProvider; el POS no se toca.
 */
interface ProductSearch
{
    /**
     * Sugerencias por texto parcial (typeahead).
     *
     * @return Collection<int, array{
     *     id:string, name:string, brand:?string, sku:?string, barcode:?string,
     *     price:float, stock:float, track_stock:bool, matched:string
     * }>
     */
    public function suggest(string $term, ?BusinessType $type = null, int $limit = 8): Collection;

    /**
     * Coincidencia exacta por código de barra / SKU / proveedor / OEM.
     *
     * @return array{
     *     type:string, product_id:string, variant_id:?string, name:string,
     *     price:float, stock:float, barcode:?string, sku:?string
     * }|null
     */
    public function findExact(string $code, ?BusinessType $type = null): ?array;
}
