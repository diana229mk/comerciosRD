<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Receta médica (uso principal: farmacias).
 * Contiene datos del médico + paciente + ítems recetados.
 * Se dispensa parcial o totalmente a través de ventas.
 */
class Prescription extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_PENDING = 'pendiente';
    public const STATUS_PARTIAL = 'parcial';
    public const STATUS_DISPENSED = 'dispensada';
    public const STATUS_EXPIRED = 'vencida';
    public const STATUS_VOID = 'anulada';

    protected $fillable = [
        'code',
        'customer_id',
        'patient_name',
        'patient_document',
        'patient_age',
        'doctor_name',
        'doctor_license',
        'doctor_exequatur',
        'doctor_specialty',
        'issued_at',
        'valid_until',
        'diagnosis',
        'notes',
        'image_path',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'valid_until' => 'date',
            'patient_age' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    // ==================== HELPERS ====================

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->valid_until && $this->valid_until->startOfDay()->lt(now()->startOfDay());
    }

    /**
     * ¿Se puede usar para dispensar? (no vencida ni anulada, con pendientes).
     */
    public function isDispensable(): bool
    {
        if ($this->isVoid() || $this->isExpired()) {
            return false;
        }

        return $this->status !== self::STATUS_DISPENSED;
    }

    /**
     * Recalcula el estado en base a las cantidades dispensadas.
     */
    public function recalculateStatus(): void
    {
        if (in_array($this->status, [self::STATUS_VOID, self::STATUS_EXPIRED], true)) {
            return;
        }

        $items = $this->items()->get();
        if ($items->isEmpty()) {
            $this->status = self::STATUS_PENDING;
            $this->saveQuietly();

            return;
        }

        $dispensed = 0;
        $pending = 0;

        foreach ($items as $it) {
            if ((float) $it->quantity_dispensed >= (float) $it->quantity_prescribed) {
                $dispensed++;
            } else {
                $pending++;
            }
        }

        if ($pending === 0) {
            $this->status = self::STATUS_DISPENSED;
        } elseif ($dispensed > 0 || $items->sum(fn ($i) => (float) $i->quantity_dispensed) > 0) {
            $this->status = self::STATUS_PARTIAL;
        } else {
            $this->status = self::STATUS_PENDING;
        }

        $this->saveQuietly();
    }

    // ==================== SCOPES ====================

    public function scopeDispensable(Builder $q): Builder
    {
        return $q->whereNotIn('status', [self::STATUS_VOID, self::STATUS_DISPENSED, self::STATUS_EXPIRED])
            ->where(function (Builder $qq) {
                $qq->whereNull('valid_until')
                    ->orWhereDate('valid_until', '>=', now()->toDateString());
            });
    }

    // ==================== BOOT ====================

    protected static function booted(): void
    {
        static::creating(function (Prescription $p): void {
            if (empty($p->code)) {
                $p->code = static::generateCode($p->business_id);
            }
            if (empty($p->status)) {
                $p->status = self::STATUS_PENDING;
            }
            if (empty($p->created_by) && auth()->check()) {
                $p->created_by = auth()->id();
            }
        });
    }

    protected static function generateCode(string $businessId): string
    {
        $prefix = 'RX-'.now()->format('Ymd').'-';
        $last = static::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('code', 'like', $prefix.'%')
            ->orderByDesc('code')
            ->value('code');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
