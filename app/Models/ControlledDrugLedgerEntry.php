<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Asiento del libro de medicamentos controlados (DIGEMAPS).
 *
 * Tipos de movimiento:
 *  - entrada             : recepción de compra
 *  - salida              : venta / dispensación
 *  - devolucion_entrada  : cliente devuelve mercancía al lote
 *  - devolucion_salida   : farmacia devuelve al proveedor
 *  - ajuste_entrada      : ajuste positivo
 *  - ajuste_salida       : ajuste negativo (mermas, roturas)
 *  - anulacion           : reversa de otro asiento
 */
class ControlledDrugLedgerEntry extends Model
{
    use BelongsToBusiness;
    use HasUuids;

    protected $table = 'controlled_drug_ledger';

    public const TYPE_ENTRY = 'entrada';
    public const TYPE_EXIT = 'salida';
    public const TYPE_RETURN_IN = 'devolucion_entrada';
    public const TYPE_RETURN_OUT = 'devolucion_salida';
    public const TYPE_ADJUST_IN = 'ajuste_entrada';
    public const TYPE_ADJUST_OUT = 'ajuste_salida';
    public const TYPE_VOID = 'anulacion';

    protected $fillable = [
        'product_id',
        'controlled_type',
        'movement_type',
        'quantity',
        'balance_after',
        'batch_id',
        'reference_id',
        'reference_type',
        'prescription_id',
        'patient_name',
        'patient_document',
        'doctor_name',
        'doctor_exequatur',
        'document_number',
        'notes',
        'created_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'recorded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    public function isEntry(): bool
    {
        return in_array($this->movement_type, [
            self::TYPE_ENTRY,
            self::TYPE_RETURN_IN,
            self::TYPE_ADJUST_IN,
        ], true);
    }

    public function isExit(): bool
    {
        return in_array($this->movement_type, [
            self::TYPE_EXIT,
            self::TYPE_RETURN_OUT,
            self::TYPE_ADJUST_OUT,
        ], true);
    }

    public function movementLabel(): string
    {
        return match ($this->movement_type) {
            self::TYPE_ENTRY => 'Entrada por compra',
            self::TYPE_EXIT => 'Salida por venta',
            self::TYPE_RETURN_IN => 'Devolución de cliente',
            self::TYPE_RETURN_OUT => 'Devolución a proveedor',
            self::TYPE_ADJUST_IN => 'Ajuste (+)',
            self::TYPE_ADJUST_OUT => 'Ajuste (-)',
            self::TYPE_VOID => 'Anulación',
            default => $this->movement_type,
        };
    }
}
