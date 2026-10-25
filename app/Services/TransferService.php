<?php

namespace App\Services;

use App\Models\Business;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransferService
{
    /**
     * Crea una transferencia en estado borrador.
     *
     * @param  array<int,array{product_id:string,quantity:float,notes?:?string}>  $items
     */
    public function create(Business $business, string $fromLocationId, string $toLocationId, array $items, ?string $notes = null): StockTransfer
    {
        if ($fromLocationId === $toLocationId) {
            throw new \InvalidArgumentException('La ubicación origen y destino deben ser distintas.');
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('Debe incluir al menos un ítem.');
        }

        return DB::transaction(function () use ($business, $fromLocationId, $toLocationId, $items, $notes) {
            $transfer = StockTransfer::create([
                'business_id' => $business->id,
                'code' => 'TRF-' . strtoupper(Str::random(8)),
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'status' => 'borrador',
                'created_by' => auth()->id(),
                'notes' => $notes,
            ]);

            foreach ($items as $it) {
                StockTransferItem::create([
                    'business_id' => $business->id,
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $it['product_id'],
                    'quantity' => $it['quantity'],
                    'received_quantity' => 0,
                    'notes' => $it['notes'] ?? null,
                ]);
            }

            return $transfer->fresh('items');
        });
    }

    /**
     * Marca la transferencia como enviada (salida del origen).
     */
    public function send(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== 'borrador') {
            throw new \RuntimeException('Solo se pueden enviar transferencias en borrador.');
        }

        $transfer->update([
            'status' => 'enviada',
            'sent_by' => auth()->id(),
            'sent_at' => now(),
        ]);

        return $transfer->refresh();
    }

    /**
     * Recibe la transferencia en destino. Si $receivedItems se pasa, actualiza las cantidades recibidas.
     *
     * @param  array<string,float>|null  $receivedItems  [transfer_item_id => qty_recibida]
     */
    public function receive(StockTransfer $transfer, ?array $receivedItems = null): StockTransfer
    {
        if ($transfer->status !== 'enviada') {
            throw new \RuntimeException('Solo se pueden recibir transferencias enviadas.');
        }

        return DB::transaction(function () use ($transfer, $receivedItems) {
            foreach ($transfer->items as $item) {
                $qty = $receivedItems[$item->id] ?? $item->quantity;
                $item->update(['received_quantity' => $qty]);
            }

            $transfer->update([
                'status' => 'recibida',
                'received_by' => auth()->id(),
                'received_at' => now(),
            ]);

            return $transfer->refresh();
        });
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status === 'recibida') {
            throw new \RuntimeException('No se puede cancelar una transferencia ya recibida.');
        }
        $transfer->update(['status' => 'cancelada']);

        return $transfer->refresh();
    }
}
