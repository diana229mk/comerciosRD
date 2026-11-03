<?php

namespace App\Models;

use App\Database\FinancialSaleItems;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Support\Itbis;
use App\Traits\BelongsToBusiness;
use App\Traits\ProtectsSaleFinancialWrites;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ProtectsSaleFinancialWrites;
    use ScopedByBranch;
    use SoftDeletes;
    use TracksBranch;

    protected $fillable = [
        // business_id: lo inyecta BelongsToBusiness
        'sale_number',
        'number', // legacy
        'customer_id',
        'customer_name',
        'prescription_id',
        'prescription_ref',
        'subtotal',
        'tax',
        'tax_rate',
        'discount',
        'total',
        'payment_method',
        'amount_paid',
        'amount_tendered',
        'change_amount',
        'change',
        'change_due', // legacy
        'status',
        'notes',
        'created_by',
        'user_id', // legacy
        'sale_date',
        'sold_at', // legacy
        'price_list_id',
        'quote_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'change' => 'decimal:2',
            'change_due' => 'decimal:2',
            'payment_method' => PaymentMethod::class,
            'status' => SaleStatus::class,
            'sale_date' => 'datetime',
            'sold_at' => 'datetime',
            'credit_version' => 'integer',
            'credit_effects_at' => 'datetime',
        ];
    }

    // ==================== RELACIONES ====================

    public function items(): HasMany
    {
        $related = $this->newRelatedInstance(SaleItem::class);

        return new FinancialSaleItems(
            $related->newQuery(), $this, $related->qualifyColumn('sale_id'), $this->getKeyName(),
        );
    }

    /**
     * Todas las asignaciones lote↔línea (trazabilidad).
     */
    public function batchAllocations(): HasMany
    {
        return $this->hasMany(SaleItemBatch::class);
    }

    /**
     * Comprobantes e-CF generados desde esta venta (módulo facturación electrónica).
     */
    public function electronicInvoices(): HasMany
    {
        return $this->hasMany(ElectronicInvoice::class);
    }

    /**
     * Primer / principal e-CF de la venta (hasOne para UI y validaciones).
     */
    public function electronicInvoice(): HasOne
    {
        return $this->hasOne(ElectronicInvoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ==================== SCOPES ====================

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SaleStatus::Completada->value,
            SaleStatus::Completed->value,
        ]);
    }

    // ==================== GENERAR NÚMERO DE VENTA ====================

    /**
     * Formato: VTA-2026-00001 (por negocio y año).
     * Usa el máximo correlativo existente (sale_number o number) para evitar duplicados.
     */
    public static function generateSaleNumber(string $businessId): string
    {
        $year = now()->year;
        $prefix = 'VTA-'.$year.'-';

        $max = 0;

        $candidates = self::withoutBusinessScope()
            ->withTrashed()
            ->where('business_id', $businessId)
            ->where(function ($q) use ($year, $prefix): void {
                $q->where('sale_number', 'like', $prefix.'%')
                    ->orWhere('number', 'like', $prefix.'%')
                    ->orWhereYear('created_at', $year);
            })
            ->get(['sale_number', 'number']);

        foreach ($candidates as $sale) {
            foreach ([$sale->sale_number, $sale->number] as $candidate) {
                if ($candidate && preg_match('/VTA-'.$year.'-(\d+)/', (string) $candidate, $matches)) {
                    $max = max($max, (int) $matches[1]);
                }
            }
        }

        $nextNumber = $max + 1;

        return $prefix.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
    }

    /** Alias usado por SaleService / POS */
    public static function nextNumber(string $businessId): string
    {
        return static::generateSaleNumber($businessId);
    }

    public function isCancellable(): bool
    {
        return $this->status?->isCompleted() ?? false;
    }

    public function isCompleted(): bool
    {
        return $this->status?->isCompleted() ?? false;
    }

    /**
     * Verifica si hay stock suficiente para completar la venta.
     * Solo considera productos con inventory_behavior = deduct_on_sale (y track_stock).
     *
     * @return list<string> Vacío si todo está bien; mensajes de error si falta stock
     */
    public function validateStock(): array
    {
        $this->loadMissing('items');

        if ($this->items->isEmpty()) {
            return [];
        }

        /** @var array<string, array{name: string, qty: float, stock: float}> $needed */
        $needed = [];

        foreach ($this->items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $item->relationLoaded('product')
                ? $item->product
                : null;

            if (! $product) {
                $product = Product::withoutBusinessScope()
                    ->where('business_id', $this->business_id)
                    ->whereKey($item->product_id)
                    ->first();
            }

            if (! $product || ! $product->shouldDeductStockOnSale()) {
                continue;
            }

            $variant = null;
            if (! empty($item->variant_id)) {
                $variant = ProductVariant::withoutBusinessScope()
                    ->where('business_id', $this->business_id)
                    ->whereKey($item->variant_id)
                    ->first();
            }

            $stockKey = (string) ($variant?->id ?? $product->getKey());
            if (! isset($needed[$stockKey])) {
                $stock = $variant
                    ? (float) $variant->stock
                    : (float) Product::withoutBusinessScope()->whereKey($product->getKey())->value('stock');

                $needed[$stockKey] = [
                    'name' => (string) ($variant ? $product->name.' · '.$variant->label() : $product->name),
                    'qty' => 0.0,
                    'stock' => $stock,
                ];
            }

            $needed[$stockKey]['qty'] += (float) $item->quantity;
        }

        $businessType = null;
        try {
            $businessType = $this->business?->business_type
                ?? Business::withoutGlobalScopes()->find($this->business_id)?->business_type
                ?? filament()->getTenant()?->business_type;
        } catch (\Throwable) {
            $businessType = null;
        }

        $prefix = match ($businessType) {
            'farmacia' => 'No hay existencia suficiente de este medicamento',
            default => 'Stock insuficiente',
        };

        $errors = [];
        foreach ($needed as $row) {
            if ($row['stock'] < $row['qty']) {
                $errors[] = "{$prefix}: «{$row['name']}» — disponible: {$row['stock']}, se intentó vender: {$row['qty']}";
            }
        }

        return $errors;
    }

    /**
     * ¿Esta venta es a crédito / fiado?
     */
    public function isCreditSale(): bool
    {
        return PaymentMethod::isCreditValue($this->payment_method);
    }

    public function recalculateTotals(): void
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('product')->get();

        $lineSpecs = [];
        $subtotal = 0.0;
        $business = $this->business;
        foreach ($items as $item) {
            $amount = (float) ($item->subtotal ?: $item->line_total ?: 0);
            $subtotal += $amount;
            $included = $item->product
                ? $item->product->priceIncludesTax($business)
                : (bool) ($business?->pricesIncludeTax() ?? false);
            $lineSpecs[] = ['amount' => $amount, 'included' => $included];
        }

        if ($lineSpecs === []) {
            $subtotal = (float) $this->items()->sum('subtotal');
            if ($subtotal <= 0) {
                $subtotal = (float) $this->items()->sum('line_total');
            }
            $lineSpecs[] = [
                'amount' => $subtotal,
                'included' => (bool) ($business?->pricesIncludeTax() ?? false),
            ];
        }

        $discount = (float) $this->discount;
        $taxRate = (float) ($this->tax_rate ?: $business?->taxRate() ?: 18);
        $totals = Itbis::totalsFromLines($lineSpecs, $discount, $taxRate);
        $paid = (float) $this->amount_paid;
        $change = max(0, round($paid - $totals['total'], 2));

        $this->forceFill([
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'change' => $change,
            'change_due' => $change,
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        static::creating(function (Sale $sale): void {
            $businessId = $sale->business_id ?: static::resolveCurrentBusinessId();

            if (empty($sale->sale_number) && $businessId) {
                $sale->sale_number = self::generateSaleNumber((string) $businessId);
            }

            if (empty($sale->number) && $sale->sale_number) {
                $sale->number = $sale->sale_number;
            }

            if (empty($sale->created_by) && auth()->check()) {
                $sale->created_by = auth()->id();
            }

            if (empty($sale->user_id) && $sale->created_by) {
                $sale->user_id = $sale->created_by;
            }

            if (empty($sale->sale_date)) {
                $sale->sale_date = now();
            }

            if (empty($sale->sold_at)) {
                $sale->sold_at = $sale->sale_date;
            }

            if (empty($sale->status)) {
                $sale->status = SaleStatus::Completada;
            }

            if ($sale->tax_rate === null) {
                $sale->tax_rate = 18;
            }
        });

        static::saving(function (Sale $sale): void {
            if ($sale->sale_number && empty($sale->number) && (! $sale->exists || $sale->isDirty('sale_number'))) {
                $sale->number = $sale->sale_number;
            }
            if ($sale->isDirty('change')) {
                $sale->change_due = $sale->change;
            } elseif ($sale->isDirty('change_due')) {
                $sale->change = $sale->change_due;
            }
            if ($sale->isDirty('sale_date') && $sale->sale_date) {
                $sale->sold_at = $sale->sale_date;
            } elseif ($sale->isDirty('sold_at') && $sale->sold_at) {
                $sale->sale_date = $sale->sold_at;
            }
        });
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class);
    }
}
