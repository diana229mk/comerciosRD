<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Support\Itbis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cotizaciones / presupuestos profesionales.
 * Estados: draft, sent, accepted, rejected, expired, converted
 */
class QuoteService
{
    public function __construct(
        protected PriceListService $priceLists,
        protected SaleService $sales,
    ) {}

    public function create(array $data): Quote
    {
        return DB::transaction(function () use ($data) {
            $businessId = $data['business_id'] ?? auth()->user()?->current_business_id;
            if (! $businessId) {
                throw new \InvalidArgumentException('business_id requerido para crear cotización.');
            }

            $quote = Quote::create([
                'code'          => $data['code'] ?? $this->nextCode($businessId),
                'customer_id'   => $data['customer_id'] ?? null,
                'customer_name' => $data['customer_name'] ?? null,
                'price_list_id' => $data['price_list_id'] ?? null,
                'status'        => 'draft',
                'issue_date'    => $data['issue_date'] ?? Carbon::today(),
                'valid_until'   => $data['valid_until'] ?? Carbon::today()->addDays(15),
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $data['created_by'] ?? auth()->id(),
                'subtotal'      => 0, 'discount' => 0, 'tax' => 0, 'total' => 0,
            ]);

            $subtotal = 0.0;
            foreach ($data['items'] ?? [] as $line) {
                $product = Product::find($line['product_id']);
                if (! $product) continue;
                $qty = (float) ($line['quantity'] ?? 1);
                $price = (float) ($line['unit_price'] ?? $product->price);
                $sub = round($qty * $price, 2);

                QuoteItem::create([
                    'quote_id'     => $quote->id,
                    'product_id'   => $product->id,
                    'variant_id'   => $line['variant_id'] ?? null,
                    'product_name' => $line['product_name'] ?? $product->name,
                    'unit_id'      => $line['unit_id'] ?? null,
                    'unit_code'    => $line['unit_code'] ?? $product->unit,
                    'quantity'     => $qty,
                    'unit_price'   => $price,
                    'subtotal'     => $sub,
                ]);
                $subtotal += $sub;
            }

            $discount = (float) ($data['discount'] ?? 0);
            $taxable  = max($subtotal - $discount, 0);
            $tax      = round($taxable * ((float) ($data['tax_rate'] ?? 0.18)), 2);
            $total    = round($taxable + $tax, 2);

            $quote->update([
                'subtotal' => $subtotal, 'discount' => $discount,
                'tax' => $tax, 'total' => $total,
            ]);

            return $quote->refresh()->load('items');
        });
    }

    public function send(Quote $q): Quote
    {
        $q->update(['status' => 'sent']);
        return $q;
    }

    public function accept(Quote $q): Quote
    {
        $q->update(['status' => 'accepted']);
        return $q;
    }

    public function reject(Quote $q): Quote
    {
        $q->update(['status' => 'rejected']);
        return $q;
    }

    /**
     * Convierte la cotización a venta llamando a SaleService.
     */
    public function convertToSale(Quote $quote, array $overrides = []): Sale
    {
        return DB::transaction(function () use ($quote, $overrides) {
            $items = $quote->items->map(fn ($i) => [
                'product_id'  => $i->product_id,
                'variant_id'  => $i->variant_id,
                'unit_id'     => $i->unit_id,
                'unit_code'   => $i->unit_code,
                'quantity'    => (float) $i->quantity,
                'unit_price'  => (float) $i->unit_price,
                'product_name'=> $i->product_name,
            ])->all();

            $sale = $this->sales->create(array_merge([
                'business_id'   => $quote->business_id,
                'customer_id'   => $quote->customer_id,
                'customer_name' => $quote->customer_name,
                'price_list_id' => $quote->price_list_id,
                'quote_id'      => $quote->id,
                'items'         => $items,
                'notes'         => 'Convertida desde cotización '.$quote->code,
            ], $overrides));

            $quote->update([
                'status'            => 'converted',
                'converted_sale_id' => $sale->id,
            ]);

            return $sale;
        });
    }

    /**
     * Cotizaciones pendientes de cobro (caja / POS).
     *
     * @return list<array{id:string,code:string,customer:string,total:float,status:string,issue_date:?string,expired:bool}>
     */
    public function pendingForPos(int $limit = 40): array
    {
        return Quote::query()
            ->with('customer:id,name')
            ->whereIn('status', [Quote::STATUS_DRAFT, Quote::STATUS_SENT, Quote::STATUS_ACCEPTED])
            ->whereNull('converted_sale_id')
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Quote $q) => [
                'id' => (string) $q->id,
                'code' => (string) $q->code,
                'customer' => $q->customer?->name ?? $q->customer_name ?? 'Cliente',
                'total' => (float) $q->total,
                'status' => (string) $q->status,
                'issue_date' => $q->issue_date?->format('d/m/Y'),
                'expired' => $q->isExpired(),
            ])
            ->values()
            ->all();
    }

    /**
     * Líneas de carrito POS a partir de una cotización.
     *
     * @return array{quote: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function posCart(Quote $quote, ?Business $business = null): array
    {
        if ($quote->converted_sale_id || $quote->status === Quote::STATUS_CONVERTED) {
            throw new InvalidArgumentException('Esa cotización ya fue cobrada.');
        }
        if (in_array($quote->status, [Quote::STATUS_CANCELLED, 'rejected'], true)) {
            throw new InvalidArgumentException('Esa cotización no se puede cobrar.');
        }

        $quote->loadMissing(['items.product', 'customer:id,name']);
        $items = [];
        foreach ($quote->items as $line) {
            $product = $line->product;
            if (! $product) {
                continue;
            }
            $variantId = $line->variant_id ? (string) $line->variant_id : null;
            $items[] = [
                'key' => $variantId ?: (string) $product->id,
                'product_id' => (string) $product->id,
                'variant_id' => $variantId,
                'name' => $line->product_name ?: $product->name,
                'image_url' => $product->imageUrl(),
                'price' => (float) $line->unit_price,
                'cost' => (float) ($product->cost ?? 0),
                'qty' => (float) $line->quantity,
                'stock' => (float) ($product->stock ?? 0),
                'barcode' => $product->barcode,
                'track_stock' => (bool) $product->track_stock,
                'deduct_on_sale' => $product->shouldDeductStockOnSale(),
                'tax_included' => $product->priceIncludesTax($business),
            ];
        }

        if ($items === []) {
            throw new InvalidArgumentException('La cotización no tiene productos para cobrar.');
        }

        return [
            'quote' => [
                'id' => (string) $quote->id,
                'code' => (string) $quote->code,
                'customer_id' => $quote->customer_id ? (string) $quote->customer_id : null,
                'customer_name' => $quote->customer?->name ?? $quote->customer_name,
                'total' => (float) $quote->total,
                'expired' => $quote->isExpired(),
            ],
            'items' => $items,
        ];
    }

    /**
     * Guarda el carrito del POS como cotización (sin cobrar ni descontar stock).
     *
     * @param  array<string, mixed>  $payload
     */
    public function createFromPos(Business $business, array $payload): Quote
    {
        $rows = $payload['items'] ?? [];
        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('Agrega productos primero.');
        }

        $prepared = [];
        $lineSpecs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
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
                throw new InvalidArgumentException('Producto no disponible.');
            }
            $variantId = $row['variant_id'] ?? null;
            $variant = null;
            if ($variantId) {
                $variant = \App\Models\ProductVariant::query()
                    ->where('business_id', $business->getKey())
                    ->where('product_id', $product->getKey())
                    ->whereKey($variantId)
                    ->where('is_active', true)
                    ->first();
                if (! $variant) {
                    throw new InvalidArgumentException('Variante no disponible para este producto.');
                }
            }
            $price = (float) ($variant?->price ?? $product->price);
            $line = [
                'product_id' => $pid,
                'variant_id' => $variant?->getKey(),
                'product_name' => (string) ($row['name'] ?? $row['product_name'] ?? $product->name),
                'unit_code' => $product->unit,
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => round($qty * $price, 2),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('quote_items', 'oem_code')) {
                $line['oem_code'] = $product->oem_code;
            }
            $prepared[] = $line;
            $lineSpecs[] = [
                'amount' => $qty * $price,
                'included' => $product->priceIncludesTax($business),
            ];
        }

        if ($prepared === []) {
            throw new InvalidArgumentException('Agrega productos primero.');
        }

        $taxRate = $business->taxRate();
        $totals = Itbis::totalsFromLines($lineSpecs, 0, $taxRate);
        $customerId = $payload['customer_id'] ?? null;
        $customerId = is_string($customerId) && $customerId !== '' ? $customerId : null;
        $customerName = filled($payload['customer_name'] ?? null) ? (string) $payload['customer_name'] : null;

        return DB::transaction(function () use ($business, $payload, $prepared, $totals, $customerId, $customerName, $taxRate): Quote {
            $quote = new Quote([
                'code' => $this->nextCode((string) $business->getKey()),
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'status' => Quote::STATUS_DRAFT,
                'issue_date' => Carbon::today(),
                'valid_until' => Carbon::today()->addDays(15),
                'notes' => filled($payload['notes'] ?? null) ? (string) $payload['notes'] : 'Creada desde caja',
                'created_by' => auth()->id(),
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
            ]);
            if (\Illuminate\Support\Facades\Schema::hasColumn('quotes', 'tax_rate')) {
                $quote->forceFill(['tax_rate' => $taxRate]);
            }
            $quote->save();

            foreach ($prepared as $line) {
                QuoteItem::create(array_merge($line, ['quote_id' => $quote->id]));
            }

            return $quote->refresh()->load(['items', 'customer']);
        });
    }

    public function markConverted(Quote $quote, Sale $sale): void
    {
        $quote->forceFill([
            'status' => Quote::STATUS_CONVERTED,
            'converted_sale_id' => $sale->id,
        ])->save();
    }

    protected function nextCode(string $businessId): string
    {
        $today = Carbon::now()->format('Ymd');
        $count = Quote::withoutGlobalScopes()
            ->where('business_id', $businessId)
            ->whereDate('created_at', Carbon::today())
            ->count() + 1;
        return 'COT-'.$today.'-'.Str::padLeft((string) $count, 4, '0');
    }
}
