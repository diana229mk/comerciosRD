<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quote extends Model
{
    use BelongsToBusiness, HasUuids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'code', 'quote_number', 'customer_id', 'customer_name', 'price_list_id',
        'status', 'issue_date', 'valid_until',
        'subtotal', 'discount', 'tax', 'total',
        'notes', 'created_by', 'converted_sale_id',
    ];
    protected $casts = [
        'issue_date' => 'date', 'valid_until' => 'date',
        'subtotal' => 'decimal:2', 'discount' => 'decimal:2',
        'tax' => 'decimal:2', 'total' => 'decimal:2',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function priceList(): BelongsTo { return $this->belongsTo(PriceList::class); }
    public function items(): HasMany { return $this->hasMany(QuoteItem::class); }
    public function convertedSale(): BelongsTo { return $this->belongsTo(Sale::class, 'converted_sale_id'); }

    public function getQuoteNumberAttribute(): string
    {
        return (string) ($this->attributes['code'] ?? $this->attributes['quote_number'] ?? '');
    }

    public function setQuoteNumberAttribute($value): void
    {
        $this->attributes['code'] = $value;
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast() && $this->status !== self::STATUS_CONVERTED;
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_ACCEPTED => 'Aceptada',
            self::STATUS_CONVERTED => 'Convertida a venta',
            self::STATUS_EXPIRED => 'Vencida',
            self::STATUS_CANCELLED => 'Cancelada',
        ];
    }
}
