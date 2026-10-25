<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Traits\BelongsToBusiness;
use App\Traits\ScopedByBranch;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use ScopedByBranch;
    use TracksBranch;
    use SoftDeletes;

    protected $fillable = [
        // business_id: trait
        'category',
        'description',
        'amount',
        'expense_date',
        'payment_method',
        'receipt',
        'notes',
        'created_by',
        'is_paid',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'is_paid' => 'boolean',
            'notes' => \App\Casts\EncryptedOrPlain::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('is_paid', true);
    }

    public function scopeBetweenDates(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('expense_date', [$from, $to]);
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return ExpenseCategory::options();
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            if (empty($expense->created_by) && auth()->check()) {
                $expense->created_by = auth()->id();
            }

            if (empty($expense->user_id) && $expense->created_by) {
                $expense->user_id = $expense->created_by;
            }

            if ($expense->is_paid === null) {
                $expense->is_paid = true;
            }

            if (empty($expense->expense_date)) {
                $expense->expense_date = now()->toDateString();
            }
        });

        // Asiento contable automático al registrar el gasto
        static::created(function (Expense $expense): void {
            \App\Services\AccountingService::createFromExpense($expense);
        });
    }
}
