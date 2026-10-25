<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\ControlledDrugLedgerEntry;
use App\Models\Product;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Libro oficial de medicamentos controlados (DIGEMAPS).
 *
 * Muestra todos los asientos (entradas / salidas / devoluciones / ajustes)
 * de los productos con controlled_type != null, con saldo running por producto.
 *
 * Filtros: rango de fechas, clasificación (anexo), producto, tipo movimiento.
 * Exportación CSV para presentar ante la autoridad sanitaria.
 */
class ControlledDrugsLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Libro de Controlados';

    protected static ?string $title = 'Libro de Medicamentos Controlados';

    protected static ?string $navigationGroup = 'Farmacia';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.pages.controlled-drugs-ledger';

    protected static ?string $slug = 'libro-controlados';

    public ?string $date_from = null;
    public ?string $date_to = null;
    public ?string $controlled_type = null;
    public ?string $product_id = null;
    public ?string $movement_type = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');

        $this->form->fill([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'controlled_type' => null,
            'product_id' => null,
            'movement_type' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Filtros')
                ->schema([
                    Forms\Components\DatePicker::make('date_from')
                        ->label('Desde')
                        ->required()
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->date_from = $state ?: $this->date_from),

                    Forms\Components\DatePicker::make('date_to')
                        ->label('Hasta')
                        ->required()
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state) => $this->date_to = $state ?: $this->date_to),

                    Forms\Components\Select::make('controlled_type')
                        ->label('Clasificación')
                        ->options([
                            'anexo_i' => 'Anexo I',
                            'anexo_ii' => 'Anexo II',
                            'anexo_iii' => 'Anexo III',
                            'psicotropico' => 'Psicotrópico',
                        ])
                        ->native(false)
                        ->placeholder('Todos')
                        ->live()
                        ->afterStateUpdated(fn ($state) => $this->controlled_type = $state),

                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->options(fn () => Product::query()
                            ->whereNotNull('controlled_type')
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->native(false)
                        ->placeholder('Todos')
                        ->live()
                        ->afterStateUpdated(fn ($state) => $this->product_id = $state),

                    Forms\Components\Select::make('movement_type')
                        ->label('Tipo de movimiento')
                        ->options([
                            ControlledDrugLedgerEntry::TYPE_ENTRY => 'Entrada por compra',
                            ControlledDrugLedgerEntry::TYPE_EXIT => 'Salida por venta',
                            ControlledDrugLedgerEntry::TYPE_RETURN_IN => 'Devolución de cliente',
                            ControlledDrugLedgerEntry::TYPE_RETURN_OUT => 'Devolución a proveedor',
                            ControlledDrugLedgerEntry::TYPE_ADJUST_IN => 'Ajuste (+)',
                            ControlledDrugLedgerEntry::TYPE_ADJUST_OUT => 'Ajuste (-)',
                        ])
                        ->native(false)
                        ->placeholder('Todos')
                        ->live()
                        ->afterStateUpdated(fn ($state) => $this->movement_type = $state),
                ])
                ->columns(3),
        ])->statePath(null);
    }

    protected function query()
    {
        return ControlledDrugLedgerEntry::query()
            ->with(['product:id,name,sku', 'batch:id,code,expires_at', 'creator:id,name', 'prescription:id,code'])
            ->when($this->date_from, fn ($q, $v) => $q->whereDate('recorded_at', '>=', $v))
            ->when($this->date_to, fn ($q, $v) => $q->whereDate('recorded_at', '<=', $v))
            ->when($this->controlled_type, fn ($q, $v) => $q->where('controlled_type', $v))
            ->when($this->product_id, fn ($q, $v) => $q->where('product_id', $v))
            ->when($this->movement_type, fn ($q, $v) => $q->where('movement_type', $v));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getEntriesProperty(): array
    {
        return $this->query()
            ->orderBy('recorded_at')
            ->orderBy('created_at')
            ->limit(1000)
            ->get()
            ->map(function (ControlledDrugLedgerEntry $e): array {
                return [
                    'id' => (string) $e->id,
                    'date' => $e->recorded_at?->format('Y-m-d H:i'),
                    'product' => $e->product?->name ?? '—',
                    'sku' => $e->product?->sku,
                    'controlled_type' => $e->controlled_type,
                    'movement_type' => $e->movement_type,
                    'movement_label' => $e->movementLabel(),
                    'is_entry' => $e->isEntry(),
                    'quantity' => (float) $e->quantity,
                    'balance_after' => (float) $e->balance_after,
                    'batch_code' => $e->batch?->code,
                    'document_number' => $e->document_number,
                    'prescription_code' => $e->prescription?->code,
                    'patient_name' => $e->patient_name,
                    'patient_document' => $e->patient_document,
                    'doctor_name' => $e->doctor_name,
                    'doctor_exequatur' => $e->doctor_exequatur,
                    'user' => $e->creator?->name,
                ];
            })
            ->all();
    }

    /**
     * Resumen por producto/tipo: entradas, salidas, saldo.
     *
     * @return list<array{product:string, controlled_type:string, entries:float, exits:float, balance:float}>
     */
    public function getSummaryProperty(): array
    {
        $rows = $this->query()->get()->groupBy('product_id');

        $out = [];
        foreach ($rows as $group) {
            $entries = 0.0;
            $exits = 0.0;
            foreach ($group as $e) {
                if ($e->isEntry()) {
                    $entries += (float) $e->quantity;
                } else {
                    $exits += (float) $e->quantity;
                }
            }
            $last = $group->sortByDesc(fn ($e) => [$e->recorded_at, $e->created_at])->first();

            $out[] = [
                'product' => $last->product?->name ?? '—',
                'controlled_type' => $last->controlled_type,
                'entries' => $entries,
                'exits' => $exits,
                'balance' => (float) $last->balance_after,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['product'], $b['product']));

        return $out;
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->entries;
        $filename = 'libro-controlados-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Fecha', 'Producto', 'SKU', 'Clasificación', 'Movimiento',
                'Cantidad', 'Saldo', 'Lote', 'Documento', 'Receta',
                'Paciente', 'Cédula', 'Médico', 'Exequátur', 'Registrado por',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['date'], $r['product'], $r['sku'], $r['controlled_type'], $r['movement_label'],
                    ($r['is_entry'] ? '+' : '-').number_format($r['quantity'], 3, '.', ''),
                    number_format($r['balance_after'], 3, '.', ''),
                    $r['batch_code'], $r['document_number'], $r['prescription_code'],
                    $r['patient_name'], $r['patient_document'], $r['doctor_name'], $r['doctor_exequatur'],
                    $r['user'],
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
