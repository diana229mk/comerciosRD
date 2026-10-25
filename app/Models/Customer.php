<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'document',
        'document_type',
        'address',
        'city',
        'notes',
        'credit_limit',
        'is_active',
        'segment',
        'preferred_size',
        'preferred_color',
        'birth_date',
        'style_profile',
        'price_list_id',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'is_active'    => 'boolean',
            'birth_date'   => 'date',
            // PII sensible (Capa 5). phone/email se dejan en claro para búsqueda POS.
            'document'     => \App\Casts\EncryptedOrPlain::class,
            'notes'        => \App\Casts\EncryptedOrPlain::class,
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyAccount()
    {
        return $this->hasOne(CustomerLoyaltyAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Alias de cédula/RNC (columna real: document).
     */
    public function getIdentificationAttribute(): ?string
    {
        return $this->document;
    }

    public function setIdentificationAttribute(?string $value): void
    {
        $this->attributes['document'] = $value;
    }

    public function getDisplayNameAttribute(): string
    {
        $parts = [$this->name];

        if ($this->phone) {
            $parts[] = "({$this->phone})";
        }

        return implode(' ', $parts);
    }

    public static function documentTypeOptions(): array
    {
        return [
            'cedula'    => 'Cédula',
            'rnc'       => 'RNC',
            'pasaporte' => 'Pasaporte',
        ];
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class);
    }
}
