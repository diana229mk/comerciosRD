<?php

namespace App\Services;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Recetas médicas: creación, dispensación parcial/total, anulación.
 * La dispensación real se hace vinculando SaleItem.prescription_item_id al
 * crear una venta; este servicio recalcula estados y valida disponibilidad.
 */
class PrescriptionService
{
    /**
     * @param  array{
     *     business_id: string,
     *     customer_id?: string|null,
     *     patient_name: string,
     *     patient_document?: string|null,
     *     patient_age?: int|null,
     *     doctor_name: string,
     *     doctor_license?: string|null,
     *     doctor_exequatur?: string|null,
     *     doctor_specialty?: string|null,
     *     issued_at: string,
     *     valid_until?: string|null,
     *     diagnosis?: string|null,
     *     notes?: string|null,
     *     image_path?: string|null,
     *     items: list<array{product_id?: string|null, product_name?: string, quantity_prescribed: float|int, dosage?: string|null, duration?: string|null, instructions?: string|null}>,
     * }  $data
     */
    public function create(array $data): Prescription
    {
        if (empty($data['items'])) {
            throw new InvalidArgumentException('La receta debe tener al menos un renglón.');
        }
        if (filled($data['image_path'] ?? null)) {
            app(PrivateDocumentStorage::class)->assertStoredForBusiness(
                (string) $data['image_path'],
                'prescriptions',
                (string) $data['business_id'],
                'image_path',
            );
        }

        return DB::transaction(function () use ($data): Prescription {
            $rx = new Prescription([
                'customer_id' => $data['customer_id'] ?? null,
                'patient_name' => $data['patient_name'],
                'patient_document' => $data['patient_document'] ?? null,
                'patient_age' => $data['patient_age'] ?? null,
                'doctor_name' => $data['doctor_name'],
                'doctor_license' => $data['doctor_license'] ?? null,
                'doctor_exequatur' => $data['doctor_exequatur'] ?? null,
                'doctor_specialty' => $data['doctor_specialty'] ?? null,
                'issued_at' => $data['issued_at'],
                'valid_until' => $data['valid_until'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'notes' => $data['notes'] ?? null,
                'image_path' => $data['image_path'] ?? null,
            ]);
            $rx->business_id = (string) $data['business_id'];
            $rx->save();

            foreach ($data['items'] as $row) {
                $productId = $row['product_id'] ?? null;
                $name = $row['product_name'] ?? null;

                if (! $name && $productId) {
                    $p = Product::withoutBusinessScope()->find($productId);
                    $name = $p?->name;
                }
                if (! $name) {
                    throw new InvalidArgumentException('Cada renglón necesita producto o descripción.');
                }

                $qty = (float) ($row['quantity_prescribed'] ?? 0);
                if ($qty <= 0) {
                    throw new InvalidArgumentException("Cantidad inválida para '{$name}'.");
                }

                $item = new PrescriptionItem([
                    'prescription_id' => $rx->id,
                    'product_id' => $productId,
                    'product_name' => $name,
                    'quantity_prescribed' => $qty,
                    'quantity_dispensed' => 0,
                    'dosage' => $row['dosage'] ?? null,
                    'duration' => $row['duration'] ?? null,
                    'instructions' => $row['instructions'] ?? null,
                ]);
                $item->business_id = (string) $data['business_id'];
                $item->save();
            }

            $rx->refresh();
            $rx->recalculateStatus();

            return $rx;
        });
    }

    /**
     * Crea una receta mínima "básica" (modo simplificado, solo referencia).
     * No exige renglones ni exequátur. Sirve para registrar rápido en el POS
     * cuando el negocio opera con pharmacy.prescription_mode = 'basic'.
     *
     * @param  array{
     *     business_id: string,
     *     customer_id?: string|null,
     *     patient_name: string,
     *     patient_document?: string|null,
     *     doctor_name?: string|null,
     *     doctor_exequatur?: string|null,
     *     issued_at?: string|null,
     *     image_path?: string|null,
     *     notes?: string|null,
     * }  $data
     */
    public function createBasic(array $data): Prescription
    {
        $patient = trim((string) ($data['patient_name'] ?? ''));
        if ($patient === '') {
            throw new InvalidArgumentException('El nombre del paciente es obligatorio.');
        }
        if (filled($data['image_path'] ?? null)) {
            app(PrivateDocumentStorage::class)->assertStoredForBusiness(
                (string) $data['image_path'],
                'prescriptions',
                (string) $data['business_id'],
                'image_path',
            );
        }

        $rx = new Prescription([
            'customer_id' => $data['customer_id'] ?? null,
            'patient_name' => $patient,
            'patient_document' => $data['patient_document'] ?? null,
            'doctor_name' => $data['doctor_name'] ?? 'No especificado',
            'doctor_exequatur' => $data['doctor_exequatur'] ?? null,
            'issued_at' => $data['issued_at'] ?? now()->toDateString(),
            'image_path' => $data['image_path'] ?? null,
            'notes' => $data['notes'] ?? 'Registro básico',
            'status' => Prescription::STATUS_DISPENSED,
        ]);
        $rx->business_id = (string) $data['business_id'];
        $rx->save();

        return $rx->fresh();
    }

    /**
     * Valida que un ítem de receta pueda dispensar la cantidad pedida.
     * Se llama desde SaleService al vincular una línea de venta.
     */
    public function assertItemDispensable(PrescriptionItem $item, float $quantity): void
    {
        $rx = $item->prescription;

        if (! $rx) {
            throw new RuntimeException('Receta no encontrada.');
        }

        if (! $rx->isDispensable()) {
            throw new RuntimeException("La receta {$rx->code} está {$rx->status} y no puede dispensarse.");
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Cantidad a dispensar debe ser mayor a cero.');
        }

        $remaining = $item->remainingQuantity();
        if ($quantity - $remaining > 0.0001) {
            throw new RuntimeException(
                "En la receta {$rx->code} solo quedan {$remaining} de '{$item->product_name}'."
            );
        }
    }

    /**
     * Aplica dispensación al ítem (se llama tras crear el SaleItem).
     */
    public function applyDispense(PrescriptionItem $item, float $quantity): void
    {
        DB::transaction(function () use ($item, $quantity): void {
            $locked = PrescriptionItem::withoutBusinessScope()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                throw new RuntimeException('Renglón de receta no encontrado.');
            }

            $locked->quantity_dispensed = (float) $locked->quantity_dispensed + $quantity;
            $locked->save();

            $rx = Prescription::withoutBusinessScope()->find($locked->prescription_id);
            $rx?->recalculateStatus();
        });
    }

    /**
     * Revierte una dispensación (al eliminar/anular una línea de venta).
     */
    public function revertDispense(PrescriptionItem $item, float $quantity): void
    {
        DB::transaction(function () use ($item, $quantity): void {
            $locked = PrescriptionItem::withoutBusinessScope()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $newValue = max(0.0, (float) $locked->quantity_dispensed - $quantity);
            $locked->quantity_dispensed = $newValue;
            $locked->save();

            $rx = Prescription::withoutBusinessScope()->find($locked->prescription_id);
            $rx?->recalculateStatus();
        });
    }

    public function void(Prescription $rx, ?string $reason = null): Prescription
    {
        $rx->status = Prescription::STATUS_VOID;
        if ($reason) {
            $rx->notes = trim(($rx->notes ? $rx->notes."\n" : '').'ANULADA: '.$reason);
        }
        $rx->save();

        return $rx;
    }
}
