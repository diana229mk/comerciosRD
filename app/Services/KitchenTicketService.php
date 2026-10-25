<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Sale;

/**
 * Pasa una venta de caja de cafetería al tablero de cocina.
 * La receta ya se descontó en el cobro; no se vuelve a descontar.
 */
class KitchenTicketService
{
    public function fromSale(Sale $sale): ?Order
    {
        $business = $sale->business ?? Business::query()->find($sale->business_id);
        if (! $business?->isCafeteria()) {
            return null;
        }

        $exists = Order::withoutBusinessScope()
            ->where('business_id', $sale->business_id)
            ->where('converted_sale_id', $sale->id)
            ->exists();
        if ($exists) {
            return null;
        }

        $sale->loadMissing('items');
        if ($sale->items->isEmpty()) {
            return null;
        }

        $order = Order::withoutBusinessScope()->forceCreate([
            'business_id' => $sale->business_id,
            'branch_id' => $sale->branch_id,
            'order_number' => Order::generateOrderNumber((string) $sale->business_id),
            'order_type' => 'por_numero',
            'customer_name' => $sale->customer_name,
            'customer_id' => $sale->customer_id,
            'status' => 'pendiente',
            'subtotal' => $sale->subtotal,
            'tax' => $sale->tax,
            'total' => $sale->total,
            'notes' => $sale->notes,
            'created_by' => $sale->user_id ?? $sale->created_by,
            'converted_sale_id' => $sale->id,
            'kitchen_issued_at' => now(),
        ]);

        foreach ($sale->items as $item) {
            if ($item->kit_line) {
                continue;
            }
            OrderItem::withoutBusinessScope()->forceCreate([
                'business_id' => $sale->business_id,
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name ?? $item->name ?? 'Ítem',
                'unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal ?? ((float) $item->unit_price * (float) $item->quantity),
            ]);
        }

        return $order;
    }
}
