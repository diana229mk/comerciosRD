<?php

namespace App\Models;

use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_id',
        'plan',
        'extra_seats',
        'amount',
        'period_start',
        'period_end',
        'due_at',
        'paid_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'extra_seats' => 'integer',
            'amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_at' => 'date',
            'paid_at' => 'datetime',
            'status' => SubscriptionInvoiceStatus::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionInvoiceStatus::Pending->value,
            SubscriptionInvoiceStatus::Overdue->value,
        ]);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            SubscriptionInvoiceStatus::Pending,
            SubscriptionInvoiceStatus::Overdue,
        ], true);
    }
}
