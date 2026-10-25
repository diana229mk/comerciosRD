<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inventario de cafetería: se descuenta al entregar a cocina (receta = kit),
 * no al teclear la orden. Ferretería/farmacia no pasan por aquí.
 *
 * Idempotente por reference (order/sale) + motivo "Cocina".
 */
class KitchenInventoryService
{
    public const REASON_PREFIX = 'Cocina';

    public function __construct(
        protected InventoryService $inventory,
        protected KitService $kits,
    ) {}

    /**
     * Descuenta ingredientes (y productos con stock) de una orden.
     * No marca kitchen_issued_at: eso lo hace el observer en el mismo save.
     */
    public function issue(Order $order): void
    {
        if ($this->hasOutbound($order)) {
            return;
        }

        $order->loadMissing('items');
        $this->consumeLines(
            $order->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'quantity' => (float) $item->quantity,
            ])->all(),
            $order,
            self::REASON_PREFIX.' '.$order->order_number,
            inbound: false,
        );
    }

    public function restore(Order $order): void
    {
        if ($this->hasInbound($order) || ! $this->hasOutbound($order)) {
            return;
        }

        $out = InventoryMovement::withoutBusinessScope()
            ->where('business_id', $order->business_id)
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', InventoryMovementType::AdjustmentOut->value)
            ->get();

        foreach ($out as $move) {
            $product = Product::withoutBusinessScope()
                ->where('business_id', $order->business_id)
                ->whereKey($move->product_id)
                ->first();
            if (! $product) {
                continue;
            }

            $this->inventory->move($product, [
                'type' => InventoryMovementType::AdjustmentIn,
                'quantity' => (float) $move->quantity,
                'reason' => self::REASON_PREFIX.' anula '.$order->order_number,
                'reference' => $order,
                'unit_cost' => $move->unit_cost,
            ]);
        }
    }

    /**
     * POS directo (sin orden): descuenta recetas de platos-kit.
     * Los no-kit los sigue descontando SaleObserver.
     */
    public function consumeSaleRecipes(Sale $sale): void
    {
        if ($this->hasOutbound($sale)) {
            return;
        }

        $sale->loadMissing('items');
        $kitLines = [];
        foreach ($sale->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($item->product_id)
                ->first();
            if ($product?->is_kit) {
                $kitLines[] = [
                    'product_id' => $product->id,
                    'quantity' => (float) $item->quantity,
                ];
            }
        }

        if ($kitLines === []) {
            return;
        }

        $this->consumeLines(
            $kitLines,
            $sale,
            self::REASON_PREFIX.' venta '.($sale->sale_number ?: $sale->number),
            inbound: false,
        );
    }

    /**
     * @param  list<array{product_id:?string, quantity:float}>  $lines
     */
    protected function consumeLines(array $lines, Model $reference, string $reason, bool $inbound): void
    {
        DB::transaction(function () use ($lines, $reference, $reason, $inbound): void {
            foreach ($lines as $line) {
                $pid = (string) ($line['product_id'] ?? '');
                $qty = (float) ($line['quantity'] ?? 0);
                if ($pid === '' || $qty <= 0) {
                    continue;
                }

                $product = Product::withoutBusinessScope()
                    ->whereKey($pid)
                    ->lockForUpdate()
                    ->first();
                if (! $product) {
                    continue;
                }

                $this->consumeProduct($product, $qty, $reference, $reason, $inbound);
            }
        });
    }

    protected function consumeProduct(
        Product $product,
        float $qty,
        Model $reference,
        string $reason,
        bool $inbound,
    ): void {
        if ($product->is_kit) {
            foreach ($this->kits->expand($product, $qty) as $comp) {
                $child = Product::withoutBusinessScope()
                    ->whereKey($comp['product_id'])
                    ->lockForUpdate()
                    ->first();
                if ($child) {
                    $this->consumeProduct($child, (float) $comp['quantity'], $reference, $reason, $inbound);
                }
            }

            return;
        }

        if (! $product->track_stock) {
            return;
        }

        $this->inventory->move($product, [
            'type' => $inbound
                ? InventoryMovementType::AdjustmentIn
                : InventoryMovementType::AdjustmentOut,
            'quantity' => $qty,
            'reason' => $reason,
            'reference' => $reference,
            'unit_cost' => $product->cost,
        ]);
    }

    protected function hasOutbound(Model $reference): bool
    {
        return InventoryMovement::withoutBusinessScope()
            ->where('business_id', $reference->getAttribute('business_id'))
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('type', InventoryMovementType::AdjustmentOut->value)
            ->where('reason', 'like', self::REASON_PREFIX.'%')
            ->exists();
    }

    protected function hasInbound(Model $reference): bool
    {
        return InventoryMovement::withoutBusinessScope()
            ->where('business_id', $reference->getAttribute('business_id'))
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('type', InventoryMovementType::AdjustmentIn->value)
            ->where('reason', 'like', self::REASON_PREFIX.'%')
            ->exists();
    }
}
