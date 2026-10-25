<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountReceivable extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'accounts_receivable';

    protected $fillable = [
        'customer_id',
        'customer_name',
        'reference',
        'description',
        'amount',
        'amount_paid',
        'issue_date',
        'due_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issue_date'  => 'date',
            'due_date'    => 'date',
            'status'      => AccountStatus::class,
            'notes'       => \App\Casts\EncryptedOrPlain::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function balance(): float
    {
        return max(0, (float) $this->amount - (float) $this->amount_paid);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            fn (AccountStatus $s) => $s->value,
            AccountStatus::openStatuses()
        ));
    }
}
