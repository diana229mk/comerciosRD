<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class JournalEntry extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'borrador';

    public const STATUS_POSTED = 'publicado';

    protected $fillable = [
        // business_id: trait
        'entry_number',
        'date',
        'reference',
        'description',
        'status',
        'total_debit',
        'total_credit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isBalanced(): bool
    {
        return abs((float) $this->total_debit - (float) $this->total_credit) < 0.01;
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * Número secuencial por negocio: ASI-2026-00001
     */
    public static function generateEntryNumber(string $businessId): string
    {
        $year = now()->year;
        $prefix = 'ASI-'.$year.'-';

        $last = self::withoutBusinessScope()
            ->where('business_id', $businessId)
            ->where('entry_number', 'like', $prefix.'%')
            ->orderByDesc('entry_number')
            ->value('entry_number');

        $next = 1;
        if (is_string($last) && preg_match('/ASI-\d{4}-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    public function recalculateTotals(): void
    {
        $debit = (float) $this->lines()->sum('debit');
        $credit = (float) $this->lines()->sum('credit');

        $this->forceFill([
            'total_debit' => round($debit, 2),
            'total_credit' => round($credit, 2),
        ])->saveQuietly();
    }

    protected static function booted(): void
    {
        static::creating(function (JournalEntry $entry): void {
            if (empty($entry->created_by) && auth()->check()) {
                $entry->created_by = auth()->id();
            }

            if (empty($entry->date)) {
                $entry->date = now()->toDateString();
            }

            if (empty($entry->status)) {
                $entry->status = self::STATUS_DRAFT;
            }

            if (empty($entry->entry_number) && $entry->business_id) {
                $entry->entry_number = self::generateEntryNumber((string) $entry->business_id);
            }
        });
    }
}
