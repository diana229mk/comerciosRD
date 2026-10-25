<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Product;

/**
 * Jerarquía: venta.price_list_id > customer.price_list_id > lista por segmento > lista default > product.price
 */
class PriceListService
{
    public function resolveList(?string $saleListId = null, ?Customer $customer = null): ?PriceList
    {
        if ($saleListId) {
            $l = PriceList::query()->where('id', $saleListId)->where('is_active', true)->first();
            if ($l) return $l;
        }
        if ($customer?->price_list_id) {
            $l = PriceList::query()->where('id', $customer->price_list_id)->where('is_active', true)->first();
            if ($l) return $l;
        }
        if ($customer?->segment) {
            $l = PriceList::query()->where('customer_segment', $customer->segment)->where('is_active', true)->first();
            if ($l) return $l;
        }
        return PriceList::query()->where('is_default', true)->where('is_active', true)->first();
    }

    /**
     * Devuelve el precio a aplicar según lista, variante y cantidad (aplica min_quantity).
     * Fallback: precio del producto.
     */
    public function resolvePrice(
        Product $product,
        ?string $variantId = null,
        float $quantity = 1.0,
        ?PriceList $list = null,
    ): float {
        $basePrice = (float) $product->price;

        if (! $list) {
            return $basePrice;
        }

        $item = PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->where('product_id', $product->id)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId), fn ($q) => $q->whereNull('variant_id'))
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity')
            ->first();

        if ($item) {
            return (float) $item->price;
        }

        // Aplicar markup/descuento global si no hay ítem específico
        $price = $basePrice;
        if ((float) $list->global_markup_pct > 0) {
            $price *= (1 + ((float) $list->global_markup_pct / 100));
        }
        if ((float) $list->global_discount_pct > 0) {
            $price *= (1 - ((float) $list->global_discount_pct / 100));
        }
        return round($price, 2);
    }
}
