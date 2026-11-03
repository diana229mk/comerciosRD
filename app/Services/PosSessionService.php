<?php

namespace App\Services;

use App\Contracts\ProductSearch;
use App\Contracts\RecordsSale;
use App\Enums\BusinessType;
use App\Enums\PaymentMethod;
use App\Models\Branch;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\User;
use App\Support\Itbis;
use App\Support\ScaleMetrics;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Lógica de caja sin estado de servidor.
 * El carrito vive en el navegador; cada request es independiente.
 * Así 100 cajas (o N VPS) no hidratan un componente Livewire por tecla.
 */
class PosSessionService
{
    public function __construct(
        protected ProductSearch $search,
        protected RecordsSale $sales,
    ) {}

    /**
     * Payload inicial de la pantalla de caja (una sola ida al servidor).
     *
     * @return array<string, mixed>
     */
    public function clientConfig(Business $business): array
    {
        $type = $business->type instanceof BusinessType ? $business->type : null;
        $cash = app(CashSessionService::class)->posSnapshot();
        $branchId = session(AuthContextResolver::SESSION_BRANCH_ID);
        $branchName = is_string($branchId) && $branchId !== ''
            ? Branch::query()->whereKey($branchId)->value('name')
            : null;

        return [
            'business' => [
                'id' => (string) $business->getKey(),
                'name' => $business->name,
                'slug' => $business->slug,
                'type' => $business->typeKey(),
                'is_pharmacy' => $business->isPharmacy(),
                'is_retail' => $business->isRetailStore(),
                'is_parts' => $type === BusinessType::Repuestos,
                'requires_customer' => $type?->requiresCustomerOnSale() ?? false,
                'tracks_expiry' => $type?->tracksExpiry() ?? false,
            ],
            'branch' => $branchName,
            'cashier' => auth()->user()?->name,
            'tax_rate' => $business->taxRate(),
            'tax_included' => $business->pricesIncludeTax(),
            'open_cash' => (bool) ($cash['open'] ?? false),
            'cash' => $cash,
            'quotes_enabled' => $this->quotesEnabled($business),
            'quotes_url' => $this->quotesEnabled($business)
                ? url('/admin/'.$business->slug.'/quotes')
                : null,
            'payment_methods' => $this->paymentMethods($business, $type),
            'cashier_id' => (string) auth()->id(),
            'customers' => Customer::query()
                ->active()
                ->orderBy('name')
                ->limit(80)
                ->get(['id', 'name'])
                ->map(fn (Customer $c) => [
                    'id' => (string) $c->id,
                    'name' => $c->name,
                ])
                ->values()
                ->all(),
            'api' => [
                'search' => route('pos.api.search', ['tenant' => $business->slug]),
                'scan' => route('pos.api.scan', ['tenant' => $business->slug]),
                'checkout' => route('pos.api.checkout', ['tenant' => $business->slug]),
                'vehicle' => route('pos.api.vehicle', ['tenant' => $business->slug]),
                'customers' => route('pos.api.customers', ['tenant' => $business->slug]),
                'cash_open' => route('pos.api.cash.open', ['tenant' => $business->slug]),
                'cash_close' => route('pos.api.cash.close', ['tenant' => $business->slug]),
                'quotes' => route('pos.api.quotes.index', ['tenant' => $business->slug]),
                'quote_save' => route('pos.api.quotes.store', ['tenant' => $business->slug]),
                'quote_load' => url('/caja/'.$business->slug.'/api/quotes'),
            ],
            'print_ticket_base' => url('/print/ticket'),
            'csrf' => csrf_token(),
            'placeholder' => match ($business->typeKey()) {
                'repuestos' => 'OEM, código, barra o nombre...',
                'farmacia' => 'Medicamento, código o barra...',
                default => 'Código de barras, SKU o nombre...',
            },
        ];
    }

    /**
     * @return list<array{id:string,name:string,brand:?string,sku:?string,barcode:?string,price:float,stock:float,track_stock:bool,matched:string}>
     */
    public function suggest(Business $business, string $term): array
    {
        $type = $business->type instanceof BusinessType ? $business->type : null;

        return $this->search->suggest($term, $type, 8)->values()->all();
    }

    /**
     * Resuelve un código a una línea de carrito, variantes, o error.
     *
     * @return array<string, mixed>
     */
    public function scan(Business $business, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'action' => 'empty', 'message' => 'Escribe un código o nombre.'];
        }

        $type = $business->type instanceof BusinessType ? $business->type : null;

        return ScaleMetrics::time('pos.scan', function () use ($business, $code, $type): array {
            if (Str::isUuid($code)) {
                $byId = Product::query()->active()->whereKey($code)->first();
                if ($byId) {
                    $blocked = $this->pharmacyBlock($business, $byId);
                    if ($blocked !== null) {
                        return $blocked;
                    }
                    if ($business->isRetailStore() && $byId->variants()->where('is_active', true)->exists()) {
                        $options = $byId->variants()->where('is_active', true)->get()->map(fn (ProductVariant $v) => [
                            'id' => (string) $v->id,
                            'sku' => $v->sku,
                            'barcode' => $v->barcode,
                            'price' => (float) ($v->price ?? $byId->price),
                            'stock' => (float) ($v->stock ?? 0),
                            'attributes' => $v->attributes ?? [],
                        ])->values()->all();

                        return [
                            'ok' => true,
                            'action' => 'variants',
                            'product_id' => (string) $byId->id,
                            'name' => $byId->name,
                            'variants' => $options,
                        ];
                    }
                    if ($byId->shouldDeductStockOnSale() && (float) $byId->stock < 1) {
                        return $this->stockError($byId, (float) $byId->stock);
                    }

                    return [
                        'ok' => true,
                        'action' => 'add',
                        'item' => $this->productLine($byId, $business),
                        'warning' => $this->expiryWarning($business, $byId),
                    ];
                }
            }

            $exact = $this->search->findExact($code, $type);

            $variant = null;
            $product = null;

            if ($exact && $exact['type'] === 'variant' && ! empty($exact['variant_id'])) {
                $variant = ProductVariant::query()->whereKey($exact['variant_id'])->first();
                $product = $variant?->product;
            } elseif ($exact && $exact['type'] === 'product') {
                $product = Product::query()->whereKey($exact['product_id'])->first();
            } else {
                $matches = $this->search->suggest($code, $type, 2);
                if ($matches->count() > 1) {
                    return [
                        'ok' => false,
                        'action' => 'ambiguous',
                        'message' => 'Hay varias coincidencias. Usa el código o un nombre más específico.',
                        'suggestions' => $matches->values()->all(),
                    ];
                }
                if ($matches->count() === 1) {
                    $product = Product::query()->whereKey($matches[0]['id'])->first();
                }
            }

            if (! $product) {
                return [
                    'ok' => false,
                    'action' => 'not_found',
                    'title' => 'Producto no encontrado',
                    'message' => $code,
                ];
            }

            if ($variant) {
                return $this->variantLine($product, $variant);
            }

            if ($business->isRetailStore() && $product->variants()->where('is_active', true)->exists()) {
                $options = $product->variants()->where('is_active', true)->get()->map(fn (ProductVariant $v) => [
                    'id' => (string) $v->id,
                    'sku' => $v->sku,
                    'barcode' => $v->barcode,
                    'price' => (float) ($v->price ?? $product->price),
                    'stock' => (float) ($v->stock ?? 0),
                    'attributes' => $v->attributes ?? [],
                ])->values()->all();

                return [
                    'ok' => true,
                    'action' => 'variants',
                    'product_id' => (string) $product->id,
                    'name' => $product->name,
                    'variants' => $options,
                ];
            }

            $blocked = $this->pharmacyBlock($business, $product);
            if ($blocked !== null) {
                return $blocked;
            }

            if ($product->shouldDeductStockOnSale() && (float) $product->stock < 1) {
                return $this->stockError($product, (float) $product->stock);
            }

            return [
                'ok' => true,
                'action' => 'add',
                'item' => $this->productLine($product, $business),
                'warning' => $this->expiryWarning($business, $product),
            ];
        }, ['business_id' => (string) $business->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function scanVariant(Business $business, string $variantId): array
    {
        $variant = ProductVariant::query()->whereKey($variantId)->first();
        if (! $variant) {
            return ['ok' => false, 'action' => 'not_found', 'message' => 'Variante no disponible.'];
        }

        $product = $variant->product;
        if (! $product) {
            return ['ok' => false, 'action' => 'not_found', 'message' => 'Producto no disponible.'];
        }

        $blocked = $this->pharmacyBlock($business, $product);
        if ($blocked !== null) {
            return $blocked;
        }

        return $this->variantLine($product, $variant);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function checkout(Business $business, array $payload): Sale
    {
        $command = [
            'entry' => 'pos', 'user_id' => (string) auth()->id(),
            'payment_method' => PaymentMethod::normalize($payload['payment_method'] ?? null)->value,
            'customer_id' => $payload['customer_id'] ?? null,
            'amount_tendered' => $payload['amount_tendered'] ?? null,
            'quote_id' => $payload['quote_id'] ?? null,
            'items' => array_map(fn (array $row) => [
                'product_id' => $row['product_id'] ?? null, 'variant_id' => $row['variant_id'] ?? null,
                'qty' => (float) ($row['qty'] ?? $row['quantity'] ?? 0),
            ], is_array($payload['items'] ?? null) ? $payload['items'] : []),
        ];

        return app(SaleCheckout::class)->run((string) $business->id,
            (string) ($payload['idempotency_key'] ?? ''), $command,
            fn () => $this->performCheckout($business, $payload));
    }

    protected function performCheckout(Business $business, array $payload): Sale
    {
        return ScaleMetrics::time('pos.checkout', function () use ($business, $payload): Sale {
            $items = $payload['items'] ?? [];
            if (! is_array($items) || $items === []) {
                throw new InvalidArgumentException('Agrega productos primero.');
            }

            $quote = $this->resolveQuoteForCheckout($business, $payload['quote_id'] ?? null);
            if ($quote) {
                $items = $quote->items()->get()->map(fn ($item) => [
                    'product_id' => (string) $item->product_id,
                    'variant_id' => $item->variant_id ? (string) $item->variant_id : null,
                    'qty' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])->all();
            }
            if ($items === []) {
                throw new InvalidArgumentException('La cotización no tiene productos para cobrar.');
            }

            $method = PaymentMethod::normalize((string) ($payload['payment_method'] ?? 'efectivo'));
            $type = $business->type instanceof BusinessType ? $business->type : null;
            $customerId = $quote ? $quote->customer_id : ($payload['customer_id'] ?? null);
            $customerId = is_string($customerId) && $customerId !== '' ? $customerId : null;

            if ($method->isCredit() && $customerId === null) {
                throw new InvalidArgumentException('Cliente requerido para fiado / crédito.');
            }
            if (($type?->requiresCustomerOnSale() ?? false) && $customerId === null) {
                throw new InvalidArgumentException('En mayoreo debe seleccionar un cliente.');
            }

            $taxRate = (float) ($quote?->tax_rate ?? $business->taxRate());
            $mapped = [];
            $lineSpecs = [];
            foreach ($items as $row) {
                if (! is_array($row)) {
                    throw new InvalidArgumentException('Producto inválido.');
                }
                $pid = (string) ($row['product_id'] ?? '');
                $qty = (float) ($row['qty'] ?? $row['quantity'] ?? 0);
                if ($pid === '' || $qty <= 0) {
                    throw new InvalidArgumentException('Cantidad o producto inválido.');
                }

                $product = Product::query()
                    ->where('business_id', $business->getKey())
                    ->whereKey($pid)
                    ->where('is_active', true)
                    ->first();
                if (! $product) {
                    throw new InvalidArgumentException('Producto no disponible en este negocio.');
                }

                $variantId = $row['variant_id'] ?? null;
                $unitPrice = isset($row['unit_price']) && $quote
                    ? (float) $row['unit_price']
                    : (float) $product->price;
                if ($variantId) {
                    $variant = ProductVariant::query()
                        ->where('business_id', $business->getKey())
                        ->where('product_id', $product->getKey())
                        ->whereKey($variantId)
                        ->where('is_active', true)
                        ->first();
                    if (! $variant) {
                        throw new InvalidArgumentException('Variante no disponible para este producto.');
                    }
                    if (! $quote) {
                        $unitPrice = (float) ($variant->price ?? $product->price);
                    }
                }

                $mapped[] = [
                    'product_id' => $pid,
                    'variant_id' => $variantId,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                ];
                $lineSpecs[] = [
                    'amount' => $unitPrice * $qty,
                    'included' => $product->priceIncludesTax($business),
                ];
            }

            if ($mapped === []) {
                throw new InvalidArgumentException('Agrega productos primero.');
            }

            $discount = (float) ($quote?->discount ?? 0);
            $total = Itbis::totalsFromLines($lineSpecs, $discount, $taxRate)['total'];
            $tendered = isset($payload['amount_tendered']) ? (float) $payload['amount_tendered'] : null;

            if ($method === PaymentMethod::Efectivo) {
                if (! app(CashSessionService::class)->currentOpen()) {
                    throw new InvalidArgumentException(
                        'Abre la caja antes de cobrar en efectivo.'
                    );
                }
                if ($tendered === null || $tendered < $total) {
                    throw new InvalidArgumentException(
                        'Debe entregar al menos RD$ '.number_format($total, 2)
                    );
                }
            }

            $amountPaid = $method->isCredit()
                ? 0.0
                : ($method === PaymentMethod::Efectivo ? (float) $tendered : $total);

            $change = $method === PaymentMethod::Efectivo
                ? max(0, round((float) $tendered - $total, 2))
                : 0.0;

            $data = [
                'business_id' => (string) $business->getKey(),
                'user_id' => auth()->id(),
                'customer_id' => $customerId ?? ($quote?->customer_id ? (string) $quote->customer_id : null),
                'items' => $mapped,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'payment_method' => $method->value,
                'amount_paid' => $amountPaid,
                'amount_tendered' => $method === PaymentMethod::Efectivo ? $tendered : null,
                'change_amount' => $method === PaymentMethod::Efectivo ? $change : null,
                'quote_id' => $quote?->id,
                'notes' => $quote ? 'Cobro de cotización '.$quote->code : null,
            ];

            $sale = $this->sales->create($data);
            if ($quote) {
                app(QuoteService::class)->markConverted($quote, $sale);
            }

            return $sale;
        }, ['business_id' => (string) $business->getKey()]);
    }

    public function quotesEnabled(Business $business): bool
    {
        if (! $business->hasModule('quotes')) {
            return false;
        }

        $user = auth()->user();

        return $user instanceof User && $user->canPerform('view_orders');
    }

    /**
     * @return list<array{id:string,code:string,customer:string,total:float,status:string,issue_date:?string,expired:bool}>
     */
    public function listQuotes(Business $business): array
    {
        $this->assertQuotes($business);

        return app(QuoteService::class)->pendingForPos();
    }

    /**
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function loadQuote(Business $business, string $quoteId): array
    {
        $this->assertQuotes($business);
        $quote = Quote::query()->whereKey($quoteId)->first();
        if (! $quote) {
            throw new InvalidArgumentException('Cotización no encontrada.');
        }

        return app(QuoteService::class)->posCart($quote, $business);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveQuote(Business $business, array $payload): Quote
    {
        $this->assertQuotes($business, write: true);

        return app(QuoteService::class)->createFromPos($business, $payload);
    }

    protected function assertQuotes(Business $business, bool $write = false): void
    {
        if (! $business->hasModule('quotes')) {
            throw new InvalidArgumentException('Este negocio no usa cotizaciones.');
        }
        $user = auth()->user();
        if (! $user instanceof User) {
            throw new InvalidArgumentException('Debes iniciar sesión.');
        }
        if ($write && ! $user->canPerform('create_orders')) {
            throw new InvalidArgumentException('Tu puesto no puede crear cotizaciones.');
        }
        if (! $write && ! $user->canPerform('view_orders')) {
            throw new InvalidArgumentException('Tu puesto no puede ver cotizaciones.');
        }
    }

    protected function resolveQuoteForCheckout(Business $business, mixed $quoteId): ?Quote
    {
        $id = is_string($quoteId) && $quoteId !== '' ? $quoteId : null;
        if ($id === null) {
            return null;
        }
        if (! $business->hasModule('quotes')) {
            throw new InvalidArgumentException('Este negocio no usa cotizaciones.');
        }
        $quote = Quote::query()->where('business_id', $business->id)->whereKey($id)->lockForUpdate()->first();
        if (! $quote) {
            throw new InvalidArgumentException('Cotización no encontrada.');
        }
        if ($quote->converted_sale_id || $quote->status === Quote::STATUS_CONVERTED) {
            throw new InvalidArgumentException('Esa cotización ya fue cobrada.');
        }

        return $quote;
    }

    private function paymentMethods(Business $business, ?BusinessType $type): array
    {
        $methods = PaymentMethod::optionsForBusinessType($type);
        if (! $business->hasModule('receivables')) {
            unset($methods[PaymentMethod::Credito->value]);
        }

        return $methods;
    }

    /**
     * @return list<array{id:string,name:string,oem_code:?string,price:float,stock:float,bin_location:?string}>
     */
    public function searchByVehicle(Business $business, ?string $make, ?string $model, ?string $year): array
    {
        if ($business->typeKey() !== 'repuestos') {
            return [];
        }

        $query = Product::query()->active();
        if (filled($make)) {
            $query->where('vehicle_make', 'like', '%'.$make.'%');
        }
        if (filled($model)) {
            $query->where('vehicle_model', 'like', '%'.$model.'%');
        }
        if (filled($year)) {
            $y = (int) $year;
            $query->where(function ($q) use ($y): void {
                $q->whereNull('year_from')
                    ->orWhere(function ($q2) use ($y): void {
                        $q2->where('year_from', '<=', $y)
                            ->where(function ($q3) use ($y): void {
                                $q3->whereNull('year_to')->orWhere('year_to', '>=', $y);
                            });
                    });
            });
        }

        return $query->orderBy('name')->limit(20)
            ->get(['id', 'name', 'oem_code', 'price', 'stock', 'bin_location'])
            ->map(fn (Product $p) => [
                'id' => (string) $p->id,
                'name' => $p->name,
                'oem_code' => $p->oem_code,
                'price' => (float) $p->price,
                'stock' => (float) $p->stock,
                'bin_location' => $p->bin_location,
            ])->values()->all();
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    public function searchCustomers(string $term): array
    {
        $term = trim($term);
        $query = Customer::query()->active()->orderBy('name')->limit(30);
        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return $query->get(['id', 'name'])->map(fn (Customer $c) => [
            'id' => (string) $c->id,
            'name' => $c->name,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function productLine(Product $product, ?Business $business = null): array
    {
        return [
            'key' => (string) $product->id,
            'product_id' => (string) $product->id,
            'variant_id' => null,
            'name' => $product->name,
            'image_url' => $product->imageUrl(),
            'price' => (float) $product->price,
            'cost' => (float) ($product->cost ?? 0),
            'qty' => 1,
            'stock' => (float) $product->stock,
            'barcode' => $product->barcode,
            'track_stock' => (bool) $product->track_stock,
            'deduct_on_sale' => $product->shouldDeductStockOnSale(),
            'tax_included' => $product->priceIncludesTax($business),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function variantLine(Product $product, ProductVariant $variant): array
    {
        if ($product->shouldDeductStockOnSale() && $variant->stock !== null && (float) $variant->stock <= 0) {
            return [
                'ok' => false,
                'action' => 'blocked',
                'title' => 'Stock insuficiente',
                'message' => 'La variante seleccionada no tiene stock.',
            ];
        }

        $attributes = $variant->attributes ?? [];
        $parts = [];
        foreach ($attributes as $k => $v) {
            $parts[] = ucfirst((string) $k).': '.$v;
        }
        $label = $product->name.($parts ? ' · '.implode(' · ', $parts) : '');

        return [
            'ok' => true,
            'action' => 'add',
            'item' => [
                'key' => (string) $variant->id,
                'product_id' => (string) $product->id,
                'variant_id' => (string) $variant->id,
                'name' => $label,
                'image_url' => $product->imageUrl(),
                'price' => (float) ($variant->price ?? $product->price),
                'cost' => (float) ($variant->cost ?? $product->cost ?? 0),
                'qty' => 1,
                'stock' => (float) ($variant->stock ?? $product->stock),
                'tax_included' => $product->priceIncludesTax(),
                'barcode' => $variant->barcode ?: $product->barcode,
                'track_stock' => true,
                'deduct_on_sale' => $product->shouldDeductStockOnSale(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pharmacyBlock(Business $business, Product $product): ?array
    {
        if (! $business->isPharmacy()) {
            return null;
        }

        if ($product->requires_prescription) {
            return [
                'ok' => false,
                'action' => 'blocked',
                'title' => 'Producto controlado',
                'message' => $product->name.' requiere receta médica. Dispensa desde el módulo de Recetas.',
            ];
        }

        if (! $product->shouldDeductStockOnSale()) {
            return null;
        }

        $blockNearDays = $business->nearExpiryBlockDays();

        if ($product->usesBatches()) {
            $sellable = $product->sellableBatchStock($blockNearDays);
            if ($sellable <= 0) {
                return [
                    'ok' => false,
                    'action' => 'blocked',
                    'title' => 'Sin lotes vendibles',
                    'message' => $product->name.' no tiene lotes vigentes disponibles.',
                ];
            }

            return null;
        }

        if ($product->expires_at) {
            $today = now()->startOfDay();
            $exp = $product->expires_at->copy()->startOfDay();
            if ($exp->lt($today)) {
                return [
                    'ok' => false,
                    'action' => 'blocked',
                    'title' => 'Medicamento vencido',
                    'message' => $product->name.' vencido el '.$product->expires_at->format('d/m/Y').'.',
                ];
            }
            if ($blockNearDays > 0) {
                $days = (int) $today->diffInDays($exp, false);
                if ($days <= $blockNearDays) {
                    return [
                        'ok' => false,
                        'action' => 'blocked',
                        'title' => 'Venta bloqueada por proximidad de vencimiento',
                        'message' => $product->name." vence en {$days} días (política: {$blockNearDays} días).",
                    ];
                }
            }
        }

        return null;
    }

    protected function expiryWarning(Business $business, Product $product): ?string
    {
        if (! $business->isPharmacy() || ! $product->expires_at) {
            return null;
        }
        $days = now()->startOfDay()->diffInDays($product->expires_at->copy()->startOfDay(), false);
        if ($days < 0) {
            return $product->name.' vencido el '.$product->expires_at->format('d/m/Y');
        }
        if ($days <= 90) {
            return $product->name.' próximo a vencer ('.$product->expires_at->format('d/m/Y').')';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function stockError(Product $product, float $available): array
    {
        $title = $product->business?->isPharmacy()
            ? 'No hay existencia suficiente de este medicamento'
            : 'Stock insuficiente';

        return [
            'ok' => false,
            'action' => 'blocked',
            'title' => $title,
            'message' => "\"{$product->name}\" — disponible: {$available}",
        ];
    }
}
