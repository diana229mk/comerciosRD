<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Enums\PurchasePaymentStatus;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource;
use App\Models\Supplier;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = PurchaseStatus::Borrador->value;
        $data['payment_status'] = $data['payment_status']
            ?? PurchasePaymentStatus::Pendiente->value;

        if (empty($data['supplier_name']) && ! empty($data['supplier_id'])) {
            $data['supplier_name'] = Supplier::query()
                ->whereKey($data['supplier_id'])
                ->value('name');
        }

        // ITBIS 18% sobre subtotal de ítems se recalcula al guardar ítems;
        // si viene del form, respetar.
        if (! isset($data['tax']) && isset($data['subtotal'])) {
            $sub = (float) $data['subtotal'];
            $data['tax'] = round($sub * 0.18, 2);
            $data['total'] = round($sub + $data['tax'], 2);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->recalculateTotals();
        // Recalcular ITBIS 18% sobre subtotal de líneas
        $subtotal = (float) $this->record->subtotal;
        $tax = round($subtotal * 0.18, 2);
        $this->record->forceFill([
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ])->saveQuietly();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
