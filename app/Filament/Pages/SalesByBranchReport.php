<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Expense;
use App\Models\InventoryMovement;
use App\Models\Sale;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * FASE S3b — Reporte de operaciones por sucursal.
 *
 * Agrega ventas, gastos y movimientos de inventario por branch_id dentro
 * de un rango de fechas. Sólo lectura; no altera nada.
 *
 * Se oculta si el negocio no tiene sucursales activas (evita ruido).
 */
class SalesByBranchReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Ventas por Sucursal';

    protected static ?string $title = 'Reporte por Sucursal';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.sales-by-branch-report';

    protected static ?string $slug = 'reportes/sucursales';

    public ?string $date_from = null;

    public ?string $date_to = null;

    public ?string $branch_id = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');
        $this->branch_id = null;

        $this->form->fill([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'branch_id' => null,
        ]);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return false;
        }

        return Branch::query()
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->exists();
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation()
            && (auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'accountant']) ?? false);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filtros')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Desde')
                            ->default(now()->startOfMonth())
                            ->required()
                            ->live(),

                        DatePicker::make('date_to')
                            ->label('Hasta')
                            ->default(now())
                            ->required()
                            ->live(),

                        Select::make('branch_id')
                            ->label('Sucursal')
                            ->placeholder('Todas las sucursales')
                            ->options(fn (): array => $this->branchOptions())
                            ->nullable()
                            ->live(),
                    ])
                    ->columns(3),
            ])
            ->statePath('');
    }

    /**
     * @return array<string, string>
     */
    protected function branchOptions(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }

        return Branch::query()
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Devuelve el rango como Carbon: from = 00:00:00, to = 23:59:59 del día.
     * Esto evita que las ventas del mismo día "to" queden fuera por hora.
     *
     * @return array{from: \Illuminate\Support\Carbon, to: \Illuminate\Support\Carbon}
     */
    protected function period(): array
    {
        $from = $this->date_from
            ? \Illuminate\Support\Carbon::parse($this->date_from)->startOfDay()
            : now()->startOfMonth();
        $to = $this->date_to
            ? \Illuminate\Support\Carbon::parse($this->date_to)->endOfDay()
            : now()->endOfDay();

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Filas del reporte: una por sucursal (más una "Sin sucursal" si aplica).
     *
     * @return list<array{
     *   branch_id: ?string,
     *   branch_name: string,
     *   sales_total: float,
     *   sales_count: int,
     *   avg_ticket: float,
     *   expenses_total: float,
     *   net: float,
     * }>
     */
    public function getRowsProperty(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return [];
        }

        ['from' => $from, 'to' => $to] = $this->period();
        $branchFilter = filled($this->branch_id) ? (string) $this->branch_id : null;

        // Ventas por sucursal en el rango (usa sale_date O sold_at, como Reports.php).
        $salesQ = Sale::query()
            ->where('business_id', $tenant->getKey())
            ->completed()
            ->where(function ($q) use ($from, $to): void {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            });

        if ($branchFilter !== null) {
            $salesQ->where('branch_id', $branchFilter);
        }

        $sales = $salesQ
            ->select('branch_id', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total) as total'))
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn ($r) => (string) ($r->branch_id ?? ''));

        // Gastos por sucursal en el rango.
        $expensesQ = Expense::query()
            ->where('business_id', $tenant->getKey())
            ->whereBetween('expense_date', [$from, $to]);

        if ($branchFilter !== null) {
            $expensesQ->where('branch_id', $branchFilter);
        }

        $expenses = $expensesQ
            ->select('branch_id', DB::raw('SUM(amount) as total'))
            ->groupBy('branch_id')
            ->get()
            ->keyBy(fn ($r) => (string) ($r->branch_id ?? ''));

        // Sucursales activas del negocio (para nombre + filas con 0 ventas).
        $branches = Branch::query()
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->when($branchFilter !== null, fn ($q) => $q->where('id', $branchFilter))
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($branches as $branch) {
            $key = (string) $branch->getKey();
            $salesTotal = (float) ($sales[$key]->total ?? 0);
            $salesCount = (int) ($sales[$key]->cnt ?? 0);
            $expensesTotal = (float) ($expenses[$key]->total ?? 0);

            $rows[] = [
                'branch_id' => $key,
                'branch_name' => (string) $branch->name,
                'sales_total' => $salesTotal,
                'sales_count' => $salesCount,
                'avg_ticket' => $salesCount > 0 ? $salesTotal / $salesCount : 0.0,
                'expenses_total' => $expensesTotal,
                'net' => $salesTotal - $expensesTotal,
            ];
        }

        // Fila "Sin sucursal" para histórico (solo si no se filtró por una específica).
        if ($branchFilter === null) {
            $orphanKey = '';
            if (isset($sales[$orphanKey]) || isset($expenses[$orphanKey])) {
                $salesTotal = (float) ($sales[$orphanKey]->total ?? 0);
                $salesCount = (int) ($sales[$orphanKey]->cnt ?? 0);
                $expensesTotal = (float) ($expenses[$orphanKey]->total ?? 0);

                $rows[] = [
                    'branch_id' => null,
                    'branch_name' => 'Sin sucursal (histórico)',
                    'sales_total' => $salesTotal,
                    'sales_count' => $salesCount,
                    'avg_ticket' => $salesCount > 0 ? $salesTotal / $salesCount : 0.0,
                    'expenses_total' => $expensesTotal,
                    'net' => $salesTotal - $expensesTotal,
                ];
            }
        }

        return $rows;
    }

    /**
     * Totales agregados de todas las filas visibles.
     *
     * @return array{sales_total: float, sales_count: int, expenses_total: float, net: float}
     */
    public function getTotalsProperty(): array
    {
        $rows = $this->rows;

        return [
            'sales_total' => array_sum(array_column($rows, 'sales_total')),
            'sales_count' => (int) array_sum(array_column($rows, 'sales_count')),
            'expenses_total' => array_sum(array_column($rows, 'expenses_total')),
            'net' => array_sum(array_column($rows, 'net')),
        ];
    }

    /**
     * Movimientos de inventario por sucursal (conteo por tipo).
     *
     * @return list<array{branch_name: string, purchase: int, sale: int, adjustment: int}>
     */
    public function getMovementsProperty(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return [];
        }

        ['from' => $from, 'to' => $to] = $this->period();
        $branchFilter = filled($this->branch_id) ? (string) $this->branch_id : null;

        $q = InventoryMovement::query()
            ->where('business_id', $tenant->getKey())
            ->whereBetween('created_at', [$from, $to]);

        if ($branchFilter !== null) {
            $q->where('branch_id', $branchFilter);
        }

        $rows = $q
            ->select('branch_id', 'type', DB::raw('COUNT(*) as cnt'))
            ->groupBy('branch_id', 'type')
            ->get();

        $branchNames = Branch::query()
            ->where('business_id', $tenant->getKey())
            ->pluck('name', 'id')
            ->all();

        $agg = [];
        foreach ($rows as $r) {
            $key = (string) ($r->branch_id ?? '');
            $type = (string) ($r->type instanceof \BackedEnum ? $r->type->value : $r->type);
            $agg[$key]['branch_name'] = $key === ''
                ? 'Sin sucursal'
                : ($branchNames[$key] ?? 'Sucursal eliminada');
            $agg[$key][$type] = (int) $r->cnt;
        }

        $out = [];
        foreach ($agg as $key => $data) {
            $out[] = [
                'branch_name' => $data['branch_name'] ?? 'N/D',
                'purchase' => (int) ($data['purchase'] ?? 0),
                'sale' => (int) ($data['sale'] ?? 0),
                'adjustment' => (int) ($data['adjustment'] ?? 0),
            ];
        }

        return $out;
    }
}
