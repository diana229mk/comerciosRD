<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación de un lote a una línea de venta (trazabilidad).
 * Registra qué cantidad de qué lote salió por qué SaleItem.
 * Permite ticket con lote, devoluciones al lote correcto y auditoría.
 */
class SaleItemBatch extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $table = 'sale_item_batches';

    protected $fillable = [
        'sale_item_id',
        'sale_id',
        'batch_id',
        'quantity',
        'unit_cost',
        'returned_quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'returned_quantity' => 'decimal:3',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    /**
     * Cantidad aún devuelta (para devoluciones parciales futuras).
     */
    public function returnableQuantity(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->returned_quantity, 3));
    }

    public function isFullyReturned(): bool
    {
        return $this->returnableQuantity() <= 0;
    }
}
