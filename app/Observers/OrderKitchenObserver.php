<?php

namespace App\Observers;

use App\Models\Business;
use App\Models\Order;
use App\Services\KitchenInventoryService;
use RuntimeException;

/**
 * Solo cafetería: al pasar a cocina se descuenta receta/ingredientes.
 * Cancelar restaura. No toca ferretería ni cotizaciones.
 */
class OrderKitchenObserver
{
    public function updating(Order $order): void
    {
        if (! $order->isDirty('status')) {
            return;
        }

        $business = $order->business
            ?? Business::query()->find($order->business_id);

        if (! $business || ! $business->isCafeteria()) {
            return;
        }

        $new = (string) $order->status;
        $issued = $order->getAttribute('kitchen_issued_at');
        $fromCobro = $order->isDirty('converted_sale_id') && filled($order->converted_sale_id);

        if (! $issued && ! $fromCobro && in_array($new, ['en_preparacion', 'lista', 'completada'], true)) {
            try {
                app(KitchenInventoryService::class)->issue($order);
            } catch (RuntimeException $e) {
                throw new RuntimeException($e->getMessage(), 0, $e);
            }
            $order->kitchen_issued_at = now();
        }

        if ($issued && $new === 'cancelada') {
            app(KitchenInventoryService::class)->restore($order);
            $order->kitchen_issued_at = null;
        }
    }
}
