<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDaily extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $table = 'sales_daily';

    protected $fillable = [
        'branch_id',
        'branch_key',
        'report_date',
        'tickets',
        'subtotal',
        'discount',
        'tax',
        'total',
        'cogs',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'tickets' => 'integer',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'cogs' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
