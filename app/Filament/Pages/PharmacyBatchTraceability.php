<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\InventoryMovement;
use App\Models\ProductBatch;
use App\Models\SaleItemBatch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de trazabilidad para farmacias.
 *
 * Ciclo completo por lote: Compra → Recepción (creación del lote) →
 * Ventas (por FEFO, con SaleItemBatch) → Devoluciones (return_in con batch_id).
 *
 * Filtros: rango de fechas, producto, código de lote.
 * Al seleccionar un lote se muestra el detalle completo del ciclo.
 * Exportación CSV del listado y del detalle.
 */
class PharmacyBatchTraceability extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Trazabilidad de Lotes';

    protected static ?string $title = 'Trazabilidad Lote → Compra → Venta → Devolución';

    protected static ?string $navigationGroup = 'Farmacia';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.pharmacy-batch-traceability';

    protected static ?string $slug = 'trazabilidad-lotes';

    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $product_search = null;
    public ?string $batch_code = null;
    public ?string $only_expiring = null; // '' | 'expired' | 'near'
    public ?string $selected_batch_id = null;

    public function mount(): void
    {
        $this->date_from = now()->subDays(90)->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');

        $this->form->fill([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'product_search' => null,
            'batch_code' => null,
            'only_expiring' => '',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->schema([
                    Forms\Components\DatePicker::make('date_from')
                        ->label('Recibido desde')
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->date_from = $state ?: $this->date_from),

                    Forms\Components\DatePicker::make('date_to')
                        ->label('Recibido hasta')
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->date_to = $state ?: $this->date_to),

                    Forms\Components\TextInput::make('product_search')
                        ->label('Producto (nombre o SKU)')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->product_search = $state),

                    Forms\Components\TextInput::make('batch_code')
                        ->label('Código de lote')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->batch_code = $state),

                    Forms\Components\Select::make('only_expiring')
                        ->label('Estado')
                        ->options([
                            '' => 'Todos',
                            'active' => 'Con stock',
                            'depleted' => 'Agotados',
                            'expired' => 'Vencidos',
                            'near' => 'Próximos a vencer (≤ 30 días)',
                        ])
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn ($state) => $this->only_expiring = $state),
                ])
                ->columns(3),
        ])->statePath(null);
    }

    /**
     * Query base de lotes con filtros aplicados.
     */
    protected function batchesQuery()
    {
        $q = ProductBatch::query()
            ->with(['product:id,name,sku'])
            ->when($this->date_from, fn ($q, $v) => $q->whereDate('received_at', '>=', $v))
            ->when($this->date_to, fn ($q, $v) => $q->whereDate('received_at', '<=', $v))
            ->when($this->batch_code, fn ($q, $v) => $q->where('code', 'like', "%{$v}%"))
            ->when($this->product_search, function ($q, $v) {
                $q->whereHas('product', fn ($qq) => $qq
                    ->where('name', 'like', "%{$v}%")
                    ->orWhere('sku', 'like', "%{$v}%"));
            });

        return match ($this->only_expiring) {
            'expired' => $q->expired(),
            'near' => $q->expiringSoon(30),
            'active' => $q->where('quantity', '>', 0),
            'depleted' => $q->where('quantity', '<=', 0),
            default => $q,
        };
    }

    /**
     * @return list<array{
     *   id: string, product: string, sku: ?string, code: ?string,
     *   received_at: ?string, expires_at: ?string, initial: float, remaining: float,
     *   sold: float, returned: float, status: string, supplier: ?string
     * }>
     */
    public function getBatchesProperty(): array
    {
        $rows = $this->batchesQuery()
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        $ids = $rows->pluck('id')->all();

        // Ventas por lote
        $sold = SaleItemBatch::query()
            ->whereIn('batch_id', $ids)
            ->selectRaw('batch_id, SUM(quantity) as q, SUM(returned_quantity) as r')
            ->groupBy('batch_id')
            ->get()
            ->keyBy('batch_id');

        // Origen (proveedor) desde la compra que creó el lote — vía inventory_movements
        $originMap = InventoryMovement::query()
            ->whereIn('batch_id', $ids)
            ->where('type', \App\Enums\InventoryMovementType::Purchase->value)
            ->with(['reference'])
            ->get()
            ->keyBy('batch_id');

        return $rows->map(function (ProductBatch $b) use ($sold, $originMap): array {
            $s = $sold->get($b->id);
            $soldQty = (float) ($s->q ?? 0);
            $retQty = (float) ($s->r ?? 0);

            $status = 'activo';
            if ($b->isExpired()) {
                $status = 'vencido';
            } elseif ((float) $b->quantity <= 0) {
                $status = 'agotado';
            } elseif ($b->isNearExpiry(30)) {
                $status = 'próximo';
            }

            $supplier = null;
            $mov = $originMap->get($b->id);
            if ($mov && $mov->reference && method_exists($mov->reference, 'supplier')) {
                $supplier = $mov->reference->supplier?->name;
            }

            return [
                'id' => (string) $b->id,
                'product' => $b->product?->name ?? '—',
                'sku' => $b->product?->sku,
                'code' => $b->code,
                'received_at' => $b->received_at?->format('Y-m-d'),
                'expires_at' => $b->expires_at?->format('Y-m-d'),
                'initial' => (float) $b->initial_quantity,
                'remaining' => (float) $b->quantity,
                'sold' => $soldQty,
                'returned' => $retQty,
                'status' => $status,
                'supplier' => $supplier,
            ];
        })->all();
    }

    /**
     * Detalle del lote seleccionado: origen (compra), ventas y devoluciones.
     *
     * @return array{
     *   batch: ?ProductBatch,
     *   origin: ?array{purchase_number:?string, supplier:?string, received_at:?string, quantity:float, cost:?float},
     *   sales: list<array{sale_number:string, sold_at:string, customer:?string, quantity:float, unit_cost:float, returned:float, remaining:float, allocation_id:string}>,
     *   returns: list<array{sale_number:?string, date:string, quantity:float, note:?string}>,
     *   totals: array{received:float, sold:float, returned:float, remaining:float}
     * }
     */
    public function getBatchDetailProperty(): array
    {
        if (! $this->selected_batch_id) {
            return ['batch' => null, 'origin' => null, 'sales' => [], 'returns' => [], 'totals' => [
                'received' => 0, 'sold' => 0, 'returned' => 0, 'remaining' => 0,
            ]];
        }

        /** @var ProductBatch|null $batch */
        $batch = ProductBatch::with('product:id,name,sku')
            ->find($this->selected_batch_id);

        if (! $batch) {
            return ['batch' => null, 'origin' => null, 'sales' => [], 'returns' => [], 'totals' => [
                'received' => 0, 'sold' => 0, 'returned' => 0, 'remaining' => 0,
            ]];
        }

        // Origen (movimiento purchase)
        $originMov = InventoryMovement::query()
            ->where('batch_id', $batch->id)
            ->where('type', \App\Enums\InventoryMovementType::Purchase->value)
            ->with('reference')
            ->first();

        $origin = null;
        if ($originMov) {
            $purchase = $originMov->reference;
            $origin = [
                'purchase_number' => $purchase?->purchase_number ?? $purchase?->number,
                'supplier' => $purchase && method_exists($purchase, 'supplier')
                    ? $purchase->supplier?->name
                    : null,
                'received_at' => $originMov->created_at?->format('Y-m-d H:i'),
                'quantity' => (float) $originMov->quantity,
                'cost' => (float) ($batch->cost ?? 0),
            ];
        }

        // Ventas del lote (allocations)
        $sales = SaleItemBatch::query()
            ->where('batch_id', $batch->id)
            ->with([
                'saleItem.sale:id,sale_number,sold_at,sale_date,customer_id,customer_name',
                'saleItem.sale.customer:id,name',
            ])
            ->orderBy('created_at')
            ->get()
            ->map(function (SaleItemBatch $a): array {
                $sale = $a->saleItem?->sale;

                return [
                    'allocation_id' => (string) $a->id,
                    'sale_number' => $sale?->sale_number ?? '—',
                    'sold_at' => optional($sale?->sold_at ?? $sale?->sale_date)?->format('Y-m-d H:i') ?? '—',
                    'customer' => $sale?->customer?->name ?? $sale?->customer_name,
                    'quantity' => (float) $a->quantity,
                    'unit_cost' => (float) ($a->unit_cost ?? 0),
                    'returned' => (float) $a->returned_quantity,
                    'remaining' => (float) $a->returnableQuantity(),
                ];
            })
            ->all();

        // Devoluciones (movimientos return_in del lote)
        $returns = InventoryMovement::query()
            ->where('batch_id', $batch->id)
            ->where('type', \App\Enums\InventoryMovementType::ReturnIn->value)
            ->with('reference:id,sale_number')
            ->orderBy('created_at')
            ->get()
            ->map(function (InventoryMovement $m): array {
                return [
                    'sale_number' => $m->reference?->sale_number,
                    'date' => $m->created_at?->format('Y-m-d H:i') ?? '—',
                    'quantity' => (float) $m->quantity,
                    'note' => $m->reason,
                ];
            })
            ->all();

        $totalSold = array_sum(array_column($sales, 'quantity'));
        $totalReturned = array_sum(array_column($returns, 'quantity'));

        return [
            'batch' => $batch,
            'origin' => $origin,
            'sales' => $sales,
            'returns' => $returns,
            'totals' => [
                'received' => (float) $batch->initial_quantity,
                'sold' => (float) $totalSold,
                'returned' => (float) $totalReturned,
                'remaining' => (float) $batch->quantity,
            ],
        ];
    }

    public function selectBatch(string $id): void
    {
        $this->selected_batch_id = $id;
    }

    public function clearSelection(): void
    {
        $this->selected_batch_id = null;
    }

    /**
     * Exporta el listado filtrado a CSV.
     */
    public function exportCsv(): StreamedResponse
    {
        $rows = $this->batches;
        $filename = 'trazabilidad-lotes-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Producto', 'SKU', 'Lote', 'Recibido', 'Vence', 'Inicial', 'Restante', 'Vendido', 'Devuelto', 'Estado', 'Proveedor']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['product'], $r['sku'], $r['code'], $r['received_at'], $r['expires_at'],
                    $r['initial'], $r['remaining'], $r['sold'], $r['returned'], $r['status'],
                    $r['supplier'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }

        if (! $tenant->isPharmacy() || ! $tenant->hasModule('reports')) {
            return false;
        }

        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
