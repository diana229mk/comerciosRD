<?php

namespace App\Services;

use App\Contracts\RecordsSale;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductVariant;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\User;
use App\Observers\SaleObserver;
use App\Support\BusinessRole;
use App\Support\EcfFeature;
use App\Support\Itbis;
use App\Support\ScaleMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class SaleService implements RecordsSale
{
    /**
     * Crea una venta multi-tenant con items, descuenta stock vía InventoryService
     * y calcula ITBIS.
     *
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
    public function convertFromOrder(Order $order, array $overrides = []): Sale
    {
        $businessId = (string) $order->business_id;
        app(SaleFinancialIntegrity::class)->assertBusiness($businessId);

        return app(SaleCheckout::class)->run($businessId, 'order:'.$order->id,
            ['entry' => 'order', 'id' => $order->id, 'overrides' => $overrides], function () use ($order, $overrides, $businessId): Sale {
                $order = Order::withoutBusinessScope()->where('business_id', $businessId)
                    ->whereKey($order->id)->lockForUpdate()->firstOrFail();
                if ($order->converted_sale_id) {
                    throw new InvalidArgumentException('La orden ya tiene una venta; no se convertirá de nuevo.');
                }
                if (isset($overrides['business_id']) && (string) $overrides['business_id'] !== $businessId) {
                    abort(404);
                }
                $order->loadMissing('items');

                if ($order->items->isEmpty()) {
                    throw new InvalidArgumentException('La cotización/orden no tiene productos para convertir.');
                }

                $items = $order->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'quantity' => (float) ($item->quantity ?? 1),
                    'unit_price' => (float) ($item->unit_price ?? 0),
                    'unit_code' => $item->unit,
                    'variant_id' => null,
                ])->all();

                $payload = array_merge([
                    'business_id' => $order->business_id,
                    'customer_id' => $order->customer_id,
                    'customer_name' => $order->customer_name,
                    'notes' => 'Convertida desde cotización '.($order->quote_number ?: $order->order_number),
                    'payment_method' => PaymentMethod::Efectivo->value,
                    'amount_paid' => (float) ($order->total ?? 0),
                    'items' => $items,
                ], $overrides);

                if ($order->kitchen_issued_at) {
                    $payload['skip_inventory'] = true;
                }

                $sale = $this->create($payload);

                $isCafeteria = $order->business?->isCafeteria()
                    ?? Business::query()->find($order->business_id)?->isCafeteria();

                if (! $order->update([
                    'status' => $isCafeteria ? 'completada' : 'converted',
                    'converted_sale_id' => $sale->id,
                    'completed_at' => $isCafeteria ? now() : $order->completed_at,
                ])) {
                    throw new RuntimeException('No se pudo confirmar la conversión de la orden.');
                }

                return $sale;
            });
    }

    public function create(array $data): Sale
    {
        return ScaleMetrics::time('checkout.create', function () use ($data): Sale {
            $businessId = (string) ($data['business_id'] ?? '');
            app(SaleFinancialIntegrity::class)->assertBusiness($businessId);
            $checkout = app(SaleCheckout::class);
            if (! $checkout->isActive($businessId) && isset($data['idempotency_key'])) {
                $command = $data;
                unset($command['idempotency_key']);
                $command['payment_method'] = PaymentMethod::normalize($data['payment_method'] ?? null)->value;

                return $checkout->run($businessId, (string) $data['idempotency_key'],
                    ['entry' => 'sale-service', 'data' => $command], fn () => $this->createSale($data));
            }
            if (PaymentMethod::isCreditValue($data['payment_method'] ?? null) && ! $checkout->isActive($businessId)) {
                throw new InvalidArgumentException('La venta a crédito requiere una clave de solicitud estable.');
            }

            return $this->createSale($data);
        }, ['business_id' => (string) ($data['business_id'] ?? '')]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createSale(array $data): Sale
    {
        $businessId = (string) ($data['business_id'] ?? '');
        app(SaleFinancialIntegrity::class)->assertBusiness($businessId);
        $itemsInput = $data['items'] ?? [];

        if ($itemsInput === []) {
            throw new InvalidArgumentException('La venta debe tener al menos un producto.');
        }

        $sale = DB::transaction(function () use ($data, $businessId, $itemsInput): Sale {
            $lines = [];
            $subtotal = 0.0;
            if (PaymentMethod::isCreditValue($data['payment_method'] ?? null)) {
                $probe = new Sale;
                $probe->forceFill([
                    'business_id' => $businessId, 'customer_id' => $data['customer_id'] ?? null,
                    'payment_method' => PaymentMethod::Credito, 'amount_paid' => '0.00',
                ]);
                app(SaleCreditService::class)->prepare($probe);
            }

            $business = Business::query()->find($businessId);
            $isPharmacy = $business?->isPharmacy() ?? false;
            $isBasicRx = $business?->pharmacyPrescriptionIsBasic() ?? false;
            $blockNearDays = $business?->nearExpiryBlockDays() ?? 0;
            $isHardware = $business?->usesAdvancedInventory() ?? false;

            // Modo básico: si viene payload de receta rápida a nivel de venta,
            // la creamos una sola vez para asociarla a las líneas sin renglón.
            $basicRx = null;
            if ($isBasicRx && ! empty($data['prescription_basic']) && is_array($data['prescription_basic'])) {
                $basicRx = app(PrescriptionService::class)->createBasic(array_merge(
                    $data['prescription_basic'],
                    ['business_id' => $businessId],
                ));
            }

            // Acumular qty por producto/variante (evita bypass de stock con líneas repetidas)
            $qtyByProduct = [];
            $rxItemsCache = []; // [prescription_item_id => PrescriptionItem]
            $rxQtyPending = []; // [prescription_item_id => qty a dispensar acumulada]

            // ---- Ferretería: expandir kits + resolver lista de precios (solo si el negocio es ferretería) ----
            $priceList = null;
            $unitSvc = app(UnitConversionService::class);
            $plSvc = app(PriceListService::class);
            if ($isHardware) {
                $itemsInput = $this->expandKits($itemsInput);
                if (isset($data['price_list_id']) || ! empty($data['customer_id'])) {
                    $customer = ! empty($data['customer_id'])
                        ? Customer::withoutGlobalScopes()->find($data['customer_id'])
                        : null;
                    $priceList = $plSvc->resolveList(
                        $data['price_list_id'] ?? null,
                        $customer,
                    );
                }
            }

            $unitSvcEarly = $unitSvc;
            foreach ($itemsInput as $row) {
                $pid = (string) ($row['product_id'] ?? '');
                $qty = (float) ($row['quantity'] ?? 0);
                if ($pid === '' || $qty <= 0) {
                    throw new InvalidArgumentException('Cantidad o producto inválido.');
                }
                // Pre-convertir a unidad base para acumulado de stock (solo ferretería)
                if ($isHardware && (! empty($row['unit_id']) || ! empty($row['unit_code']))) {
                    $p = Product::withoutBusinessScope()->find($pid);
                    if ($p) {
                        $u = $unitSvcEarly->resolveUnit($p, $row['unit_id'] ?? null, $row['unit_code'] ?? null);
                        $qty = $unitSvcEarly->toBase($qty, $u);
                    }
                }
                $variantKey = (string) ($row['variant_id'] ?? $pid);
                $qtyByProduct[$variantKey] = ($qtyByProduct[$variantKey] ?? 0) + $qty;
            }

            if ($qtyByProduct === []) {
                throw new InvalidArgumentException('La venta debe tener al menos un producto.');
            }

            foreach ($itemsInput as $row) {
                $qty = (float) ($row['quantity'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Cantidad inválida.');
                }

                /** @var Product|null $product */
                $product = Product::withoutBusinessScope()
                    ->where('business_id', $businessId)
                    ->where('id', $row['product_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $product || ! $product->is_active) {
                    throw new InvalidArgumentException('Producto no disponible en este negocio.');
                }

                // Ferretería: convertir unidad a base (caja→100 unidades, etc.)
                $unit = null;
                $unitFactor = 1.0;
                if ($isHardware) {
                    $unit = $unitSvc->resolveUnit(
                        $product,
                        $row['unit_id'] ?? null,
                        $row['unit_code'] ?? null,
                    );
                    $unitFactor = $unitSvc->factor($unit);
                    if ($unitFactor !== 1.0) {
                        $qty = $unitSvc->toBase($qty, $unit);
                    }

                    // Resolver precio desde lista si aplica (por unidad base)
                    if ($priceList && ! isset($row['unit_price'])) {
                        $row['unit_price'] = $plSvc->resolvePrice(
                            $product,
                            $row['variant_id'] ?? null,
                            $qty,
                            $priceList,
                        );
                    }
                }

                // Validar stock total solicitado del producto/variante (suma de líneas)
                $variantId = $row['variant_id'] ?? null;
                $variant = null;
                if (! empty($variantId)) {
                    $variant = ProductVariant::withoutBusinessScope()
                        ->where('business_id', $businessId)
                        ->whereKey($variantId)
                        ->first();
                }

                $totalQtyForProduct = (float) ($qtyByProduct[(string) ($variantId ?? $product->id)] ?? $qty);
                $availableStock = $variant ? (float) $variant->stock : (float) $product->stock;
                if ($product->shouldDeductStockOnSale() && $availableStock < $totalQtyForProduct) {
                    $label = $variant ? $product->name.' · '.$variant->label() : $product->name;
                    $msg = "Stock insuficiente para \"{$label}\". Disponible: {$availableStock}, solicitado: {$totalQtyForProduct}";
                    if ($isHardware) {
                        $subs = app(SubstituteService::class)->forProduct($product, $totalQtyForProduct);
                        if ($subs->isNotEmpty()) {
                            $msg .= '. Sustitutos disponibles: '.$subs->map(fn ($s) => "{$s->name} ({$s->sku}, stock {$s->stock})")->join(' | ');
                        }
                    }
                    throw new RuntimeException($msg);
                }

                // Farmacia: bloquear venta de vencidos / próximos a vencer.
                // Aplica solo cuando el producto descuenta stock al vender.
                if ($isPharmacy && $product->shouldDeductStockOnSale()) {
                    $this->assertPharmacyStockSellable(
                        $product,
                        $totalQtyForProduct,
                        $blockNearDays,
                    );
                }

                // Farmacia: si el producto requiere receta, la línea debe traer
                // prescription_item_id válido y con saldo suficiente.
                // Excepción modo básico: se acepta sin renglón cuando el producto
                // NO es controlado (Anexo I/II/psicotrópico), siempre y cuando
                // haya al menos una referencia mínima (receta básica de la venta
                // o prescription_ref en la línea).
                $rxItemId = $row['prescription_item_id'] ?? null;
                $rxRef = $row['prescription_ref'] ?? ($data['prescription_ref'] ?? null);
                $isControlled = ! empty($product->controlled_type);
                if ($isPharmacy && $product->requires_prescription) {
                    if (! $rxItemId) {
                        $allowBasic = $isBasicRx && ! $isControlled && ($basicRx !== null || $rxRef);
                        if (! $allowBasic) {
                            throw new RuntimeException(
                                "\"{$product->name}\" requiere receta médica. Asocia un renglón de receta a la línea."
                            );
                        }
                    }
                }

                if ($rxItemId) {
                    $rxItem = $rxItemsCache[$rxItemId] ?? PrescriptionItem::withoutBusinessScope()
                        ->where('business_id', $businessId)
                        ->whereKey($rxItemId)
                        ->with('prescription')
                        ->lockForUpdate()
                        ->first();

                    if (! $rxItem) {
                        throw new RuntimeException('Renglón de receta no encontrado.');
                    }

                    if ($rxItem->product_id && $rxItem->product_id !== (string) $product->id) {
                        throw new RuntimeException(
                            "El renglón de la receta no corresponde a \"{$product->name}\"."
                        );
                    }

                    $rxItemsCache[$rxItemId] = $rxItem;
                    $rxQtyPending[$rxItemId] = ($rxQtyPending[$rxItemId] ?? 0.0) + $qty;

                    // Validar acumulado contra saldo disponible en la receta
                    app(PrescriptionService::class)->assertItemDispensable(
                        $rxItem,
                        $rxQtyPending[$rxItemId],
                    );
                }

                // Farmacia: si el producto es medicamento controlado, exigir
                // exequátur/cédula/receta según anexo (DIGEMAPS).
                if ($isPharmacy) {
                    app(ControlledDrugLedgerService::class)->assertDispenseAllowed(
                        $product,
                        $rxItemsCache[$rxItemId] ?? null,
                    );
                }

                $unitPrice = isset($row['unit_price'])
                    ? (float) $row['unit_price']
                    : (float) $product->price;
                // Componente de kit: no cobra (el precio lo lleva el padre)
                if (! empty($row['kit_line'])) {
                    $unitPrice = 0.0;
                }
                $lineTotal = round($unitPrice * $qty, 2);
                $subtotal += $lineTotal;

                $lines[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'unit_cost' => (float) $product->cost,
                    'subtotal' => $lineTotal,
                    'prescription_item_id' => $rxItemId,
                    'variant_id' => $row['variant_id'] ?? null,
                    'unit_id' => $unit?->id,
                    'unit_code' => $unit?->unit_code ?? ($row['unit_code'] ?? null),
                    'unit_factor' => $unitFactor,
                    'kit_line' => (bool) ($row['kit_line'] ?? false),
                    'parent_sale_item_ref' => $row['parent_sale_item_ref'] ?? null,
                    'kit_ref' => $row['kit_ref'] ?? null,
                ];
            }

            $discount = max(0, (float) ($data['discount'] ?? 0));
            $business = Business::query()->find($businessId);
            $taxRate = (float) ($data['tax_rate'] ?? $business?->taxRate() ?? 18);
            $lineSpecs = [];
            foreach ($lines as $line) {
                $product = $line['product'] ?? null;
                $included = $product instanceof Product
                    ? $product->priceIncludesTax($business)
                    : (bool) ($business?->pricesIncludeTax() ?? false);
                $lineSpecs[] = [
                    'amount' => (float) $line['subtotal'],
                    'included' => $included,
                ];
            }
            $totals = Itbis::totalsFromLines($lineSpecs, $discount, $taxRate);
            $tax = $totals['tax'];
            $total = $totals['total'];

            $paymentMethod = PaymentMethod::normalize((string) ($data['payment_method'] ?? 'efectivo'));

            // Crédito / fiado requiere cliente
            if ($paymentMethod->isCredit() && empty($data['customer_id'])) {
                throw new InvalidArgumentException(
                    'Debes seleccionar un cliente para ventas a fiado / crédito.'
                );
            }

            $amountPaid = (float) ($data['amount_paid'] ?? $total);
            if ($paymentMethod->isCredit()) {
                $amountPaid = 0;
            } elseif ($amountPaid < $total) {
                throw new InvalidArgumentException('El monto pagado es menor al total.');
            }

            $change = max(0, round($amountPaid - $total, 2));

            // Monto entregado (efectivo) y vuelto explícitos si vienen del modal Cobrar
            $amountTendered = null;
            $changeAmount = null;
            if ($paymentMethod === PaymentMethod::Efectivo) {
                $amountTendered = array_key_exists('amount_tendered', $data)
                    ? (float) $data['amount_tendered']
                    : $amountPaid;
                $changeAmount = array_key_exists('change_amount', $data)
                    ? (float) $data['change_amount']
                    : $change;
                $change = $changeAmount;
            }

            $number = Sale::generateSaleNumber($businessId);

            $sale = new Sale([
                'sale_number' => $number,
                'number' => $number,
                'customer_id' => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'user_id' => $data['user_id'] ?? auth()->id(),
                'created_by' => $data['user_id'] ?? auth()->id(),
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax' => $tax,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'amount_paid' => $amountPaid,
                'amount_tendered' => $amountTendered,
                'change_amount' => $changeAmount,
                'change' => $change,
                'change_due' => $change,
                'status' => SaleStatus::Completada,
                'notes' => $data['notes'] ?? null,
                'prescription_id' => $basicRx?->id ?? ($data['prescription_id'] ?? null),
                'prescription_ref' => $data['prescription_ref'] ?? null,
                'price_list_id' => $priceList?->id ?? ($data['price_list_id'] ?? null),
                'quote_id' => $data['quote_id'] ?? null,
                'sale_date' => now(),
                'sold_at' => now(),
            ]);
            $sale->business_id = $businessId;

            return app(SaleFinancialIntegrity::class)->construct($sale, function (Sale $sale) use ($lines, $businessId, $rxQtyPending, $rxItemsCache, $data): Sale {

                $refToItemId = []; // mapea parent_sale_item_ref -> saleItem->id
                foreach ($lines as $idx => $line) {
                    /** @var Product $product */
                    $product = $line['product'];

                    $item = new SaleItem([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'prescription_item_id' => $line['prescription_item_id'] ?? null,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'unit' => $line['unit_code'] ?? $product->unit,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'unit_cost' => $line['unit_cost'],
                        'subtotal' => $line['subtotal'],
                        'line_total' => $line['subtotal'],
                        'variant_id' => $line['variant_id'] ?? null,
                        'unit_id' => $line['unit_id'] ?? null,
                        'unit_factor' => $line['unit_factor'] ?? 1,
                        'kit_line' => (bool) ($line['kit_line'] ?? false),
                        'parent_sale_item_id' => isset($line['parent_sale_item_ref'])
                            ? ($refToItemId[$line['parent_sale_item_ref']] ?? null)
                            : null,
                    ]);
                    $item->business_id = $businessId;
                    $item->save();
                    // Guardar id como referencia por si líneas siguientes lo referencian
                    $refToItemId['idx-'.$idx] = $item->id;
                    if (! empty($line['kit_ref'])) {
                        $refToItemId[$line['kit_ref']] = $item->id;
                    }
                }

                // Aplicar dispensación acumulada en las recetas asociadas
                if ($rxQtyPending !== []) {
                    $rxSvc = app(PrescriptionService::class);
                    foreach ($rxQtyPending as $rxItemId => $qty) {
                        $rxItem = $rxItemsCache[$rxItemId] ?? PrescriptionItem::withoutBusinessScope()
                            ->find($rxItemId);
                        if ($rxItem) {
                            $rxSvc->applyDispense($rxItem, (float) $qty);
                        }
                    }
                }

                // Ítems listos → inventario + contabilidad + caja (idempotente)
                app(SaleFinancialIntegrity::class)->seal($sale);
                $sale->load('items');
                app(SaleObserver::class)->processCompletedSale(
                    $sale,
                    skipInventory: (bool) ($data['skip_inventory'] ?? false),
                );

                return $sale->load(['items', 'customer', 'cashier', 'creator']);
            });
        });

        return $sale;
    }

    /**
     * Devolución parcial (o total) de una venta con reposición al lote y,
     * opcionalmente, reembolso automático al cliente.
     *
     * $quantities: [sale_item_batch_id => quantity_to_return]
     *
     * Efectos de mercancía (siempre):
     *  - Aumenta cantidad en el lote.
     *  - Actualiza stock cacheado del producto.
     *  - Crea InventoryMovement tipo return_in con batch_id (trazable).
     *  - Actualiza returned_quantity en cada SaleItemBatch.
     *
     * Efectos de dinero (si $applyRefund = true):
     *  - Efectivo → resta el monto (subtotal + ITBIS proporcional) de la caja abierta.
     *  - Fiado/crédito → reduce el balance de la CxC de la venta (si existe).
     *  - Otros métodos (tarjeta/transferencia) → solo se calcula el monto y se
     *    devuelve como resumen (el reembolso real se hace en el terminal).
     *
     * @param  array<string, float|int|string>  $quantities
     * @return array{movements: list<InventoryMovement>, refund_amount: float, refund_channel: string, refund_note: ?string}
     */
    public function returnFromAllocations(
        Sale $sale,
        array $quantities,
        bool $applyRefund = true,
        ?bool $issueCreditNote = null,
    ): array {
        $sale->assertBelongsToCurrentBusiness();

        if (! $this->canReturn($sale)) {
            abort(403, 'No tienes permiso para procesar devoluciones.');
        }

        if (! $sale->isCompleted()) {
            throw new RuntimeException('Solo se pueden devolver productos de ventas completadas.');
        }

        $normalized = [];
        foreach ($quantities as $id => $qty) {
            $qty = (float) $qty;
            if (! is_finite($qty) || $qty < 0) {
                throw new InvalidArgumentException('La cantidad a devolver debe ser un número no negativo.');
            }
            if ($qty === 0.0) {
                continue;
            }
            $normalized[(string) $id] = round($qty, 3);
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Debes indicar al menos una cantidad a devolver.');
        }

        return DB::transaction(function () use ($sale, $normalized, $applyRefund, $issueCreditNote): array {
            $lockedSale = Sale::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $lockedSale->isCompleted()) {
                throw new RuntimeException('Solo se pueden devolver productos de ventas completadas.');
            }

            $allocations = SaleItemBatch::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->get();

            $requestedAllocations = $allocations->whereIn('id', array_keys($normalized));
            if ($requestedAllocations->count() !== count($normalized)) {
                throw new InvalidArgumentException('Alguna asignación no pertenece a esta venta.');
            }

            $saleItems = SaleItem::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereIn('id', $allocations->pluck('sale_item_id')->unique())
                ->get()
                ->keyBy('id');
            $previouslyReturnedBase = 0.0;
            foreach ($allocations as $allocation) {
                $saleItem = $saleItems->get($allocation->sale_item_id);
                if (! $saleItem || (string) $saleItem->sale_id !== (string) $lockedSale->id) {
                    throw new RuntimeException('Línea de venta no encontrada.');
                }
                $previouslyReturnedBase += (float) $saleItem->unit_price * (float) $allocation->returned_quantity;
            }

            $refundBase = 0.0;
            foreach ($requestedAllocations as $allocation) {
                $requested = $normalized[(string) $allocation->id];
                $pending = $allocation->returnableQuantity();

                if ($requested > $pending + 1e-6) {
                    throw new RuntimeException(
                        "No puedes devolver {$requested} de este lote: solo quedan {$pending} por devolver."
                    );
                }

                $saleItem = $saleItems->get($allocation->sale_item_id);
                if (! $saleItem || (string) $saleItem->sale_id !== (string) $lockedSale->id) {
                    throw new RuntimeException('Línea de venta no encontrada.');
                }
                $refundBase += (float) $saleItem->unit_price * $requested;
            }

            $saleSubtotal = (float) $lockedSale->subtotal;
            $saleTotal = (float) $lockedSale->total;
            if ($saleSubtotal <= 0 && $refundBase > 0) {
                throw new RuntimeException('No se puede calcular el reembolso de una venta sin subtotal válido.');
            }

            $previousRefundAmount = $saleSubtotal > 0
                ? round(min($saleTotal, $previouslyReturnedBase * $saleTotal / $saleSubtotal), 2)
                : 0.0;
            $cumulativeRefundAmount = $saleSubtotal > 0
                ? round(min($saleTotal, ($previouslyReturnedBase + $refundBase) * $saleTotal / $saleSubtotal), 2)
                : 0.0;
            $refundAmount = round(max(0, $cumulativeRefundAmount - $previousRefundAmount), 2);

            $method = $lockedSale->payment_method;
            $isCreditSale = PaymentMethod::isCreditValue(
                $method instanceof PaymentMethod ? $method->value : (string) $method
            );
            if ($applyRefund && ! $isCreditSale
                && $cumulativeRefundAmount > (float) $lockedSale->amount_paid + 0.001) {
                throw new RuntimeException('El reembolso acumulado no puede superar el monto pagado.');
            }

            $movements = [];

            foreach ($requestedAllocations as $alloc) {
                $requested = $normalized[(string) $alloc->id];
                $saleItem = $saleItems->get($alloc->sale_item_id);

                /** @var Product $product */
                $product = Product::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)
                    ->whereKey($saleItem->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $batch = ProductBatch::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)
                    ->where('product_id', $product->id)
                    ->whereKey($alloc->batch_id)
                    ->lockForUpdate()
                    ->first();

                if (! $batch) {
                    throw new RuntimeException('El lote original ya no existe; usa anular venta.');
                }

                $batch->quantity = round((float) $batch->quantity + $requested, 3);
                $batch->saveQuietly();

                $newStock = round((float) $product->stock + $requested, 3);
                $product->forceFill(['stock' => $newStock])->saveQuietly();

                $reason = 'Devolución venta #'.($sale->sale_number ?: $sale->number)
                    .($batch->code ? ' · Lote '.$batch->code : '');

                $movement = new InventoryMovement([
                    'product_id' => $product->id,
                    'type' => InventoryMovement::TYPE_RETURN_IN,
                    'quantity' => $requested,
                    'stock_after' => $newStock,
                    'unit_cost' => $batch->cost ?? $product->cost,
                    'reason' => $reason,
                    'reference_id' => $sale->id,
                    'reference_type' => Sale::class,
                    'created_by' => auth()->id(),
                ]);
                $movement->business_id = $sale->business_id;
                $movement->batch_id = $batch->id;
                $movement->save();

                $alloc->forceFill([
                    'returned_quantity' => round((float) $alloc->returned_quantity + $requested, 3),
                ])->save();

                // Libro DIGEMAPS: devolución de cliente = entrada al inventario
                app(ControlledDrugLedgerService::class)
                    ->recordSaleReturn($sale, $product, $requested, $batch);

                $movements[] = $movement;
            }

            $refundChannel = 'none';
            $refundNote = null;

            if ($applyRefund && $refundAmount > 0) {
                [$refundChannel, $refundNote] = $this->applyRefund($lockedSale, $refundAmount);
            }

            // Nota de Crédito e-CF (tipo 34) — si el negocio la habilita.
            $creditNote = null;
            $creditNoteError = null;
            $shouldIssue = EcfFeature::enabled() && $issueCreditNote;
            if ($shouldIssue === null) {
                $business = $sale->business ?: Business::query()->find($sale->business_id);
                $shouldIssue = EcfFeature::enabled() && $business
                    ? ((bool) ($business->settings['ecf']['auto_credit_note_on_return'] ?? false))
                    : false;
            }

            if ($shouldIssue) {
                try {
                    $creditNote = EcfService::createCreditNoteForReturn(
                        sale: $sale,
                        allocationQuantities: $normalized,
                        modificationCode: 3, // Devolución de mercancía
                    );
                } catch (\Throwable $e) {
                    $creditNoteError = $e->getMessage();
                    Log::warning('Devolución: no se pudo emitir Nota de Crédito e-CF', [
                        'sale_id' => $sale->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'movements' => $movements,
                'refund_amount' => $refundAmount,
                'refund_channel' => $refundChannel,
                'refund_note' => $refundNote,
                'credit_note' => $creditNote,
                'credit_note_error' => $creditNoteError,
            ];
        });
    }

    public function canReturn(Sale $sale, ?User $user = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User || ! ($user->is_active ?? true)) {
            return false;
        }

        return in_array(
            BusinessRole::currentRole($user, (string) $sale->business_id),
            ['owner', 'admin', 'supervisor'],
            true,
        );
    }

    /**
     * Aplica el reembolso al canal correcto según método de pago:
     *  - Efectivo → resta de caja abierta (si existe).
     *  - Crédito/fiado → reduce el balance de la CxC de la venta.
     *  - Otros (tarjeta/transferencia) → no aplica automáticamente.
     *
     * @return array{0:string,1:?string} [canal, nota]
     */
    protected function applyRefund(Sale $sale, float $amount): array
    {
        $method = $sale->payment_method;
        if ($method instanceof PaymentMethod) {
            $methodKey = $method->value;
        } else {
            $methodKey = (string) $method;
        }
        $normalized = PaymentMethod::normalize($methodKey);

        // Efectivo → caja
        if (in_array($normalized, [PaymentMethod::Efectivo, PaymentMethod::Cash], true)) {
            try {
                $reversed = app(CashSessionService::class)
                    ->reverseCashAmount((string) $sale->business_id, $amount);

                if (! $reversed) {
                    return [
                        'cash_failed',
                        'No había caja abierta — registra el reembolso manualmente: RD$ '.number_format($amount, 2),
                    ];
                }

                return ['cash', 'Reembolso restado de la caja abierta: RD$ '.number_format($amount, 2)];
            } catch (\Throwable $e) {
                Log::warning('Devolución: no se pudo restar de caja', [
                    'sale_id' => $sale->id,
                    'error' => $e->getMessage(),
                ]);

                return ['cash_failed', 'No había caja abierta — registra el reembolso manualmente: RD$ '.number_format($amount, 2)];
            }
        }

        // Fiado / crédito → CxC
        if ($normalized->isCredit()) {
            if ((int) $sale->credit_version === 1) {
                $receivable = app(SaleCreditService::class)->refund($sale, number_format($amount, 2, '.', ''));
                $extra = (float) $receivable->paid_amount > (float) $receivable->amount
                    ? ' — el cliente ya había pagado más de lo que quedó, verifica reembolso.' : '';

                return ['receivable', 'CxC reducida en RD$ '.number_format($amount, 2).$extra];
            }
            $saleNumber = $sale->sale_number ?: $sale->number ?: (string) $sale->id;
            $reference = 'Venta '.$saleNumber;

            $receivable = Receivable::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->where('reference', $reference)
                ->first();

            if (! $receivable) {
                return ['credit_no_receivable', 'No se encontró la CxC de esta venta; ajusta manualmente: RD$ '.number_format($amount, 2)];
            }

            $currentAmount = (float) $receivable->amount;
            $paid = (float) $receivable->paid_amount;

            // Reducir el total de la CxC. Si ya se pagó más de lo que queda, el excedente
            // representa dinero que debe reembolsarse (no lo tocamos automáticamente).
            $newAmount = round(max(0, $currentAmount - $amount), 2);
            $newBalance = round(max(0, $newAmount - $paid), 2);

            $receivable->forceFill([
                'amount' => $newAmount,
                'balance' => $newBalance,
                'notes' => trim(($receivable->notes ?: '')."\nDevolución: RD$ ".number_format($amount, 2).' el '.now()->format('d/m/Y H:i')),
            ])->saveQuietly();
            $receivable->refreshPaymentStatus();

            $extra = $paid > $newAmount
                ? ' — el cliente ya había pagado más de lo que quedó, verifica reembolso.'
                : '';

            return ['receivable', 'CxC reducida en RD$ '.number_format($amount, 2).$extra];
        }

        // Tarjeta / transferencia → no automático
        return ['manual', 'Método '.strtoupper($methodKey).': procesa el reembolso en el terminal (RD$ '.number_format($amount, 2).').'];
    }

    /**
     * Anula una venta completada y restaura stock vía InventoryService.
     */
    public function cancel(Sale $sale): Sale
    {
        $sale->assertBelongsToCurrentBusiness();

        if (! $sale->isCancellable()) {
            throw new RuntimeException('Solo se pueden anular ventas completadas.');
        }

        return DB::transaction(function () use ($sale): Sale {
            $sale = Sale::withoutBusinessScope()
                ->where('business_id', $sale->business_id)
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $sale->isCancellable()) {
                throw new RuntimeException('Solo se pueden anular ventas completadas.');
            }

            $sale->load('items');

            app(SaleObserver::class)->restoreSaleMovements($sale);

            // Marcar anulada → SaleObserver::updated() revierte total_sales_cash (efectivo)
            $sale->status = SaleStatus::Anulada;
            $sale->save();

            return $sale->fresh(['items', 'customer', 'cashier', 'creator']);
        });
    }

    /**
     * Farmacia: valida que el producto tenga stock vendible suficiente
     * (excluye vencidos y opcionalmente próximos a vencer).
     *
     * Reglas:
     *  - Si el producto usa lotes: verificar que la suma de lotes vendibles ≥ qty.
     *  - Si no usa lotes pero tiene expires_at: bloquear si el producto está
     *    vencido, o si está dentro del rango de proximidad configurado.
     */
    protected function assertPharmacyStockSellable(
        Product $product,
        float $quantity,
        int $blockNearDays,
    ): void {
        if ($product->usesBatches()) {
            $sellable = $product->sellableBatchStock($blockNearDays);

            if ($sellable + 1e-6 < $quantity) {
                $reason = $blockNearDays > 0
                    ? "Solo hay {$sellable} unidades en lotes vigentes (excluyendo vencidos y próximos a vencer en {$blockNearDays} días)."
                    : "Solo hay {$sellable} unidades en lotes vigentes (excluyendo vencidos).";

                throw new RuntimeException(
                    "No se puede vender \"{$product->name}\": {$reason} Solicitado: {$quantity}."
                );
            }

            return;
        }

        // Producto sin lotes: usar expires_at del producto (si existe)
        if ($product->expires_at) {
            $today = now()->startOfDay();
            $exp = $product->expires_at->copy()->startOfDay();

            if ($exp->lt($today)) {
                throw new RuntimeException(
                    "No se puede vender \"{$product->name}\": producto vencido el "
                    .$product->expires_at->format('d/m/Y').'.'
                );
            }

            if ($blockNearDays > 0) {
                $days = (int) $today->diffInDays($exp, false);
                if ($days <= $blockNearDays) {
                    throw new RuntimeException(
                        "No se puede vender \"{$product->name}\": vence en {$days} días (política: "
                        ."bloquear ventas dentro de {$blockNearDays} días)."
                    );
                }
            }
        }
    }

    /**
     * Expande productos tipo kit en líneas hijas.
     * Cada línea kit genera:
     *  - una línea "padre" (product->is_kit) con precio del kit
     *  - líneas "hijas" (kit_line=true) por componente, precio 0, cantidad = kit_qty * component_qty
     * Se conservan tal cual las líneas no-kit.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function expandKits(array $items): array
    {
        $expanded = [];
        foreach ($items as $row) {
            $product = Product::withoutBusinessScope()->find($row['product_id'] ?? null);
            if (! $product || ! $product->is_kit) {
                $expanded[] = $row;

                continue;
            }
            $ref = 'kit-'.($row['product_id']).'-'.count($expanded);
            $row['kit_ref'] = $ref;
            $expanded[] = $row;

            foreach (app(KitService::class)->expand($product, (float) ($row['quantity'] ?? 1)) as $comp) {
                $expanded[] = [
                    'product_id' => $comp['product_id'],
                    'variant_id' => $comp['variant_id'] ?? null,
                    'quantity' => $comp['quantity'],
                    'kit_line' => true,
                    'parent_sale_item_ref' => $ref,
                    'product_name' => $comp['product_name'] ?? null,
                ];
            }
        }

        return $expanded;
    }
}
