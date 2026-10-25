<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use App\Traits\TracksBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use TracksBranch;

    protected $fillable = [
        'stock_count_id', 'product_id', 'batch_id',
        'expected_quantity', 'counted_quantity', 'difference',
        'unit_cost', 'cost_impact', 'adjustment_movement_id', 'notes',
    ];

    protected $casts = [
        'expected_quantity' => 'decimal:3',
        'counted_quantity' => 'decimal:3',
        'difference' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'cost_impact' => 'decimal:2',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function adjustmentMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'adjustment_movement_id');
    }
}
