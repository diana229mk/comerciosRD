<?php

namespace App\Services;

use App\Models\ControlledDrugLedgerEntry;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Libro de registro de medicamentos controlados (DIGEMAPS).
 *
 * - Solo actúa cuando el producto tiene controlled_type != null.
 * - Mantiene un saldo running por producto (balance_after).
 * - Aplica reglas de validación reforzadas para Anexo I y Anexo II
 *   (exequátur del médico + cédula del paciente + receta activa).
 */
class ControlledDrugLedgerService
{
    /** Anexos que exigen receta con exequátur y cédula. */
    protected const STRICT_TYPES = ['anexo_i', 'anexo_ii', 'psicotropico'];

    public function isControlled(Product $product): bool
    {
        return filled($product->controlled_type);
    }

    /**
     * Valida los requisitos de dispensación de un producto controlado.
     * Se llama desde SaleService antes de crear la línea.
     */
    public function assertDispenseAllowed(
        Product $product,
        ?PrescriptionItem $rxItem,
    ): void {
        if (! $this->isControlled($product)) {
            return;
        }

        // Todo controlado exige receta
        if (! $rxItem) {
            throw new RuntimeException(
                "\"{$product->name}\" es medicamento controlado ({$product->controlled_type}) y exige receta."
            );
        }

        $rx = $rxItem->prescription ?: Prescription::withoutBusinessScope()->find($rxItem->prescription_id);
        if (! $rx) {
            throw new RuntimeException('Receta asociada no encontrada.');
        }

        // Anexo I/II y psicotrópicos: reglas estrictas
        if (in_array($product->controlled_type, self::STRICT_TYPES, true)) {
            if (blank($rx->doctor_exequatur)) {
                throw new RuntimeException(
                    "\"{$product->name}\" ({$product->controlled_type}) requiere exequátur del médico en la receta {$rx->code}."
                );
            }
            if (blank($rx->patient_document)) {
                throw new RuntimeException(
                    "\"{$product->name}\" ({$product->controlled_type}) requiere cédula del paciente en la receta {$rx->code}."
                );
            }
            if ($rx->isExpired()) {
                throw new RuntimeException("La receta {$rx->code} está vencida y no puede dispensar controlados.");
            }
        }
    }

    /**
     * Registra una entrada por compra (recepción).
     */
    public function recordPurchaseEntry(
        Purchase $purchase,
        Product $product,
        float $qty,
        ?ProductBatch $batch = null,
    ): ?ControlledDrugLedgerEntry {
        if (! $this->isControlled($product) || $qty <= 0) {
            return null;
        }

        return $this->write(
            product: $product,
            movementType: ControlledDrugLedgerEntry::TYPE_ENTRY,
            quantity: $qty,
            batch: $batch,
            reference: $purchase,
            documentNumber: $purchase->purchase_number ?? $purchase->number ?? null,
        );
    }

    /**
     * Registra una salida por venta / dispensación.
     */
    public function recordSaleExit(
        Sale $sale,
        SaleItem $saleItem,
        Product $product,
        float $qty,
        ?ProductBatch $batch = null,
    ): ?ControlledDrugLedgerEntry {
        if (! $this->isControlled($product) || $qty <= 0) {
            return null;
        }

        $rx = null;
        $rxItem = null;
        if ($saleItem->prescription_item_id) {
            $rxItem = PrescriptionItem::withoutBusinessScope()
                ->with('prescription')
                ->find($saleItem->prescription_item_id);
            $rx = $rxItem?->prescription;
        }

        return $this->write(
            product: $product,
            movementType: ControlledDrugLedgerEntry::TYPE_EXIT,
            quantity: $qty,
            batch: $batch,
            reference: $sale,
            documentNumber: $sale->sale_number ?? $sale->number ?? null,
            prescription: $rx,
            patientName: $rx?->patient_name ?? $sale->customer_name,
            patientDocument: $rx?->patient_document,
            doctorName: $rx?->doctor_name,
            doctorExequatur: $rx?->doctor_exequatur,
        );
    }

    /**
     * Registra una devolución de cliente al lote (entrada).
     */
    public function recordSaleReturn(
        Sale $sale,
        Product $product,
        float $qty,
        ?ProductBatch $batch = null,
    ): ?ControlledDrugLedgerEntry {
        if (! $this->isControlled($product) || $qty <= 0) {
            return null;
        }

        return $this->write(
            product: $product,
            movementType: ControlledDrugLedgerEntry::TYPE_RETURN_IN,
            quantity: $qty,
            batch: $batch,
            reference: $sale,
            documentNumber: $sale->sale_number ?? null,
            notes: 'Devolución de cliente sobre venta '.($sale->sale_number ?? ''),
        );
    }

    /**
     * Escribe el asiento con saldo running (lock por producto).
     */
    protected function write(
        Product $product,
        string $movementType,
        float $quantity,
        ?ProductBatch $batch = null,
        ?Model $reference = null,
        ?string $documentNumber = null,
        ?Prescription $prescription = null,
        ?string $patientName = null,
        ?string $patientDocument = null,
        ?string $doctorName = null,
        ?string $doctorExequatur = null,
        ?string $notes = null,
    ): ControlledDrugLedgerEntry {
        return DB::transaction(function () use (
            $product, $movementType, $quantity, $batch, $reference, $documentNumber,
            $prescription, $patientName, $patientDocument, $doctorName, $doctorExequatur, $notes
        ): ControlledDrugLedgerEntry {

            // Saldo previo del producto en el libro (con lock)
            $last = ControlledDrugLedgerEntry::withoutBusinessScope()
                ->where('business_id', $product->business_id)
                ->where('product_id', $product->id)
                ->orderByDesc('recorded_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $previous = $last ? (float) $last->balance_after : 0.0;

            $signed = in_array($movementType, [
                ControlledDrugLedgerEntry::TYPE_ENTRY,
                ControlledDrugLedgerEntry::TYPE_RETURN_IN,
                ControlledDrugLedgerEntry::TYPE_ADJUST_IN,
            ], true) ? +$quantity : -$quantity;

            $newBalance = round($previous + $signed, 3);

            $entry = new ControlledDrugLedgerEntry([
                'product_id' => $product->id,
                'controlled_type' => (string) $product->controlled_type,
                'movement_type' => $movementType,
                'quantity' => abs($quantity),
                'balance_after' => $newBalance,
                'batch_id' => $batch?->id,
                'reference_id' => $reference?->getKey(),
                'reference_type' => $reference ? $reference::class : null,
                'prescription_id' => $prescription?->id,
                'patient_name' => $patientName,
                'patient_document' => $patientDocument,
                'doctor_name' => $doctorName,
                'doctor_exequatur' => $doctorExequatur,
                'document_number' => $documentNumber,
                'notes' => $notes,
                'created_by' => auth()->id(),
                'recorded_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
            $entry->business_id = (string) $product->business_id;
            $entry->save();

            return $entry;
        });
    }
}
