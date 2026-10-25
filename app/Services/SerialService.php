<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductSerial;
use App\Models\Sale;
use Illuminate\Support\Carbon;

/**
 * Gestión de números de serie / IMEI para herramientas eléctricas y equipos de valor.
 */
class SerialService
{
    /**
     * Retorna serial disponibles para asignar en venta (status = available).
     */
    public function available(Product $product, int $limit = 20)
    {
        return ProductSerial::query()
            ->where('product_id', $product->id)
            ->where('status', 'available')
            ->limit($limit)
            ->get();
    }

    /**
     * Marca una lista de seriales como vendidos (los ata a la venta).
     * @param  array<string>  $serialIds
     */
    public function markSold(array $serialIds, Sale $sale): int
    {
        if (empty($serialIds)) return 0;
        return ProductSerial::query()
            ->whereIn('id', $serialIds)
            ->where('status', 'available')
            ->update([
                'status'          => 'sold',
                'sold_in_sale_id' => $sale->id,
                'sold_at'         => Carbon::now(),
            ]);
    }

    /**
     * Retornar seriales tras devolución (los libera de nuevo).
     */
    public function returnFromSale(Sale $sale): int
    {
        return ProductSerial::query()
            ->where('sold_in_sale_id', $sale->id)
            ->update([
                'status'          => 'available',
                'sold_in_sale_id' => null,
                'sold_at'         => null,
            ]);
    }
}
