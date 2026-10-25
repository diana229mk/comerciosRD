<?php

namespace App\Contracts;

use App\Models\Sale;

/**
 * Único writer de ventas completadas.
 *
 * El POS Filament, un POS futuro (API) y la conversión desde cotización
 * deben pasar por aquí. No insertar filas en `sales` desde Resources.
 */
interface RecordsSale
{
    /**
     * @param  array{
     *     business_id: string,
     *     user_id?: string|null,
     *     customer_id?: string|null,
     *     customer_name?: string|null,
     *     items: list<array{product_id: string, quantity: float|int, unit_price?: float|int}>,
     *     discount?: float|int,
     *     tax_rate?: float|int,
     *     payment_method?: string,
     *     amount_paid?: float|int,
     *     notes?: string|null,
     * }  $data
     */
    public function create(array $data): Sale;
}
