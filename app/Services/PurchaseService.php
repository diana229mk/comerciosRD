<?php

namespace App\Services;

use App\Enums\InventoryMovementType;
use App\Enums\PurchasePaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Completa compras: inventario + cuenta por pagar (si aplica).
 */
class PurchaseService
{
    public function __construct(
        protected InventoryService $inventory,
    ) {}

    /**
     * Marca la compra como recibida: entra stock y opcionalmente crea payable.
     */
    public function receive(Purchase $purchase): Purchase
    {
        return DB::transaction(function () use ($purchase): Purchase {
            /** @var Purchase $locked */
            $locked = Purchase::query()
                ->whereKey($purchase->getKey())
                ->lockForUpdate()
                ->with(['items.product', 'supplier'])
                ->firstOrFail();

            if ($locked->isReceived()) {
                throw new RuntimeException('Esta compra ya fue recibida.');
            }

            if ($locked->isAnulada()) {
                throw new RuntimeException('No se puede recibir una compra anulada.');
            }

            if ($locked->items->isEmpty()) {
                throw new RuntimeException('La compra no tiene productos.');
            }

            foreach ($locked->items as $item) {
                $this->receiveItem($locked, $item);
            }

            $locked->recalculateTotals();
            $locked->refresh();

            $payableId = $this->maybeCreatePayable($locked);

            $locked->forceFill([
                'status' => PurchaseStatus::Recibida,
                'received_at' => now(),
                'payable_id' => $payableId ?? $locked->payable_id,
            ])->save();

            return $locked->fresh(['items', 'supplier', 'payable']);
        });
    }

    protected function receiveItem(Purchase $purchase, PurchaseItem $item): void
    {
        if (! $item->product_id) {
            throw new RuntimeException(
                "El ítem \"{$item->product_name}\" no tiene producto vinculado."
            );
        }

        /** @var Product|null $product */
        $product = Product::query()->whereKey($item->product_id)->first();
        if (! $product) {
            throw new RuntimeException(
                "Producto no encontrado para \"{$item->product_name}\"."
            );
        }

        $qty = (float) $item->quantity;
        if ($qty <= 0) {
            throw new RuntimeException(
                "Cantidad inválida para \"{$item->product_name}\"."
            );
        }

        $unitCost = (float) $item->unit_cost;

        if ($product->track_stock) {
            $business = $purchase->business
                ?? Business::query()->find($purchase->business_id);

            // Farmacia: cada línea de compra es un lote (requiere expires_at)
            if ($business?->isPharmacy()) {
                if (empty($item->expires_at)) {
                    throw new RuntimeException(
                        "Farmacia: la línea \"{$item->product_name}\" requiere fecha de vencimiento."
                    );
                }

                $this->receivePharmacyBatch($purchase, $item, $product, $qty, $unitCost);
            } else {
                $this->inventory->move($product, [
                    'type' => InventoryMovementType::Purchase,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'reason' => 'Compra '.$purchase->purchase_number,
                    'reference' => $purchase,
                    'created_by' => $purchase->created_by ?? auth()->id(),
                ]);
            }
        }

        if ($unitCost > 0) {
            $product->forceFill(['cost' => $unitCost])->saveQuietly();
        }
    }

    /**
     * Crea un ProductBatch para la línea de compra y su movimiento de inventario.
     * Requiere que $item->expires_at esté seteado (validado antes).
     */
    protected function receivePharmacyBatch(
        Purchase $purchase,
        PurchaseItem $item,
        Product $product,
        float $qty,
        float $unitCost,
    ): void {
        $batch = new ProductBatch([
            'product_id' => $product->id,
            'code' => $item->batch_code,
            'quantity' => $qty,
            'initial_quantity' => $qty,
            'cost' => $unitCost,
            'expires_at' => $item->expires_at,
            'received_at' => $purchase->received_at?->toDateString() ?? now()->toDateString(),
            'created_by' => $purchase->created_by ?? auth()->id(),
        ]);
        $batch->business_id = $purchase->business_id;
        $batch->save();

        $item->forceFill(['batch_id' => $batch->id])->saveQuietly();

        // Actualiza stock del producto y crea movimiento trazable al lote.
        $newStock = round((float) $product->stock + $qty, 3);
        $product->forceFill(['stock' => $newStock])->saveQuietly();

        $movement = new InventoryMovement([
            'product_id' => $product->id,
            'type' => InventoryMovementType::Purchase,
            'quantity' => $qty,
            'stock_after' => $newStock,
            'unit_cost' => $unitCost,
            'reason' => 'Compra '.$purchase->purchase_number
                .($item->batch_code ? ' · Lote '.$item->batch_code : ''),
            'reference_id' => $purchase->getKey(),
            'reference_type' => Purchase::class,
            'created_by' => $purchase->created_by ?? auth()->id(),
        ]);
        $movement->business_id = $purchase->business_id;
        $movement->batch_id = $batch->id;
        $movement->save();

        // Libro DIGEMAPS: entrada por compra de controlado
        app(ControlledDrugLedgerService::class)
            ->recordPurchaseEntry($purchase, $product, $qty, $batch);
    }

    /**
     * Crea cuenta por pagar si el pago no está liquidado.
     */
    protected function maybeCreatePayable(Purchase $purchase): ?string
    {
        $total = (float) $purchase->total;
        $paid = (float) ($purchase->amount_paid ?? 0);
        $payment = $purchase->paymentStatusValue();

        if ($payment === PurchasePaymentStatus::Pagada->value) {
            return null;
        }

        $balance = round(max(0, $total - $paid), 2);

        // Si está pendiente y no pagó nada, balance = total
        if ($balance <= 0 && $payment === PurchasePaymentStatus::Pendiente->value) {
            $balance = $total;
            $paid = 0;
        }

        if ($balance <= 0) {
            return null;
        }

        if ($purchase->payable_id) {
            return (string) $purchase->payable_id;
        }

        $supplierName = $purchase->supplier_name
            ?: $purchase->supplier?->name
            ?: 'Proveedor';

        $payable = new Payable([
            'supplier_id' => $purchase->supplier_id,
            'supplier_name' => $supplierName,
            'reference' => $purchase->invoice_number ?: $purchase->purchase_number,
            'description' => 'Compra '.$purchase->purchase_number,
            'amount' => $total,
            'paid_amount' => $paid,
            'due_date' => $purchase->due_date?->toDateString()
                ?? now()->addDays(15)->toDateString(),
            'status' => $paid > 0 ? 'parcial' : 'pendiente',
            'notes' => 'Generada automáticamente desde compra '.$purchase->purchase_number,
            'created_by' => $purchase->created_by ?? auth()->id(),
        ]);
        $payable->business_id = $purchase->business_id;
        $payable->save();

        return (string) $payable->getKey();
    }

    public function cancel(Purchase $purchase): Purchase
    {
        if ($purchase->isReceived()) {
            throw new RuntimeException(
                'No se puede anular una compra ya recibida (el stock ya entró).'
            );
        }

        $purchase->forceFill([
            'status' => PurchaseStatus::Anulada,
        ])->save();

        return $purchase->fresh();
    }

    /**
     * Recepción PARCIAL: solo suma al stock la cantidad indicada por línea
     * (queda pendiente por recibir la diferencia).
     * $quantities = [purchase_item_id => qty_a_recibir]
     * Puede llamarse varias veces hasta completar la compra.
     */
    public function receivePartial(Purchase $purchase, array $quantities): Purchase
    {
        return DB::transaction(function () use ($purchase, $quantities): Purchase {
            /** @var Purchase $p */
            $p = Purchase::query()->whereKey($purchase->getKey())
                ->lockForUpdate()->with(['items.product', 'supplier'])->firstOrFail();

            if ($p->isReceived()) {
                throw new RuntimeException('Esta compra ya fue recibida totalmente.');
            }
            if ($p->isAnulada()) {
                throw new RuntimeException('Compra anulada.');
            }

            $business = $p->business ?? Business::query()->find($p->business_id);

            foreach ($p->items as $item) {
                $qty = (float) ($quantities[$item->id] ?? 0);
                if ($qty <= 0) continue;

                $pending = (float) $item->quantity - (float) ($item->received_quantity ?? 0);
                if ($qty > $pending + 0.001) {
                    throw new RuntimeException(
                        "Cantidad a recibir excede lo pendiente para \"{$item->product_name}\". Pendiente: {$pending}"
                    );
                }

                // Fija la cantidad de esta recepción y reutiliza receiveItem
                $originalQty = $item->quantity;
                $item->quantity = $qty;
                $this->receiveItem($p, $item);
                $item->quantity = $originalQty;
                $item->received_quantity = (float) ($item->received_quantity ?? 0) + $qty;
                $item->saveQuietly();

                // AVCO + registro multi-proveedor (solo ferretería)
                if ($business?->usesAdvancedInventory() && $item->product) {
                    $this->applyAvcoAndSupplier($item->product, $p, (float) $item->unit_cost, $qty);
                }
            }

            // ¿Terminó? Si todas las líneas están completas → status Recibida.
            $allComplete = $p->items()->get()->every(
                fn ($it) => (float) $it->received_quantity >= (float) $it->quantity - 0.001
            );

            $p->forceFill([
                'status' => $allComplete ? PurchaseStatus::Recibida : PurchaseStatus::Borrador,
                'is_partial' => ! $allComplete,
                'received_at' => $allComplete ? now() : $p->received_at,
            ])->save();

            if ($allComplete) {
                $payableId = $this->maybeCreatePayable($p);
                if ($payableId) {
                    $p->forceFill(['payable_id' => $payableId])->save();
                }
            }

            return $p->fresh(['items', 'supplier', 'payable']);
        });
    }

    /**
     * Costo promedio ponderado (AVCO): mezcla stock/costo previo con lo recibido.
     * Se aplica solo en ferretería (farmacia ya está gestionada por lotes/FEFO).
     */
    protected function applyAvcoAndSupplier(Product $product, Purchase $purchase, float $unitCost, float $qty): void
    {
        // Nota: al llegar aquí el stock del producto YA fue incrementado por receiveItem.
        // Reconstruimos el stock previo restando qty entrada.
        $stockPost = (float) $product->fresh()->stock;
        $stockPrev = max(0.0, $stockPost - $qty);
        $costPrev = (float) $product->cost;

        $denom = $stockPrev + $qty;
        $avco = $denom > 0
            ? round((($stockPrev * $costPrev) + ($qty * $unitCost)) / $denom, 4)
            : $unitCost;

        // Sobreescribimos la última asignación de receiveItem (que fija cost = unitCost).
        $product->forceFill(['cost' => $avco])->saveQuietly();

        // Registrar/actualizar ProductSupplier
        if ($purchase->supplier_id) {
            $ps = \App\Models\ProductSupplier::firstOrNew([
                'business_id' => $purchase->business_id,
                'product_id' => $product->id,
                'supplier_id' => $purchase->supplier_id,
            ]);
            $ps->last_cost = $unitCost;
            $ps->last_quantity = $qty;
            $ps->last_purchase_date = now()->toDateString();
            $ps->save();
        }
    }

    /**
     * Recepción TOTAL con AVCO + multi-proveedor. Wrapper sobre receive() para ferretería.
     */
    public function receiveWithAvco(Purchase $purchase): Purchase
    {
        $business = Business::query()->find($purchase->business_id);
        $isHw = $business?->usesAdvancedInventory() ?? false;

        $result = $this->receive($purchase);

        if ($isHw) {
            foreach ($result->items as $item) {
                if ($item->product) {
                    $this->applyAvcoAndSupplier($item->product, $result, (float) $item->unit_cost, (float) $item->quantity);
                }
                $item->forceFill(['received_quantity' => $item->quantity])->saveQuietly();
            }
        }

        return $result->fresh(['items', 'supplier', 'payable']);
    }
}
