<?php

namespace App\Filament\Pages;

use App\Exports\SalesReportExport;
use App\Models\Business;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Payable;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reportes';

    protected static ?string $title = 'Reportes Avanzados';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.reports';

    protected static ?string $slug = 'reportes';

    public ?string $date_from = null;

    public ?string $date_to = null;

    public function mount(): void
    {
        $this->date_from = now()->startOfMonth()->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');
        $this->form->fill([
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
        ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar Excel')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->action(function (): BinaryFileResponse {
                    $from = $this->date_from ?: now()->startOfMonth()->format('Y-m-d');
                    $to = $this->date_to ?: now()->format('Y-m-d');
                    $fileName = 'reporte-ventas-'.$from.'-a-'.$to.'.xlsx';

                    return Excel::download(
                        new SalesReportExport($from, $to),
                        $fileName
                    );
                }),

            Action::make('export_pdf')
                ->label('Exportar PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->action(function (): StreamedResponse {
                    $from = $this->date_from ?: now()->startOfMonth()->format('Y-m-d');
                    $to = $this->date_to ?: now()->format('Y-m-d');

                    $data = [
                        'date_from' => $from,
                        'date_to' => $to,
                        'salesSummary' => $this->salesSummary,
                        'profitSummary' => $this->profitSummary,
                        'topProducts' => $this->topProducts,
                        'receivablesSummary' => $this->receivablesSummary,
                        'payablesSummary' => $this->payablesSummary,
                        'inventoryValue' => $this->inventoryValue,
                        'business' => filament()->getTenant(),
                    ];

                    $pdf = Pdf::loadView('pdf.reports', $data)
                        ->setPaper('letter', 'portrait');

                    $fileName = 'reporte-'.$from.'-a-'.$to.'.pdf';

                    return response()->streamDownload(function () use ($pdf): void {
                        echo $pdf->output();
                    }, $fileName, [
                        'Content-Type' => 'application/pdf',
                    ]);
                }),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Filtros')
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Desde')
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->date_from = $state ?: $this->date_from;
                            }),

                        DatePicker::make('date_to')
                            ->label('Hasta')
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->date_to = $state ?: $this->date_to;
                            }),
                    ])
                    ->columns(2),
            ])
            // Enlaza los campos a las propiedades públicas del componente
            ->statePath(null);
    }

    // ==================== DATOS DE REPORTES ====================

    /**
     * @return array{total: float, tax: float, discount: float, count: int, cash: float, card: float, average: float}
     */
    public function getSalesSummaryProperty(): array
    {
        [$from, $to] = $this->period();

        $base = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            });

        $totalSales = (float) (clone $base)->sum('total');
        $totalTax = (float) (clone $base)->sum('tax');
        $totalDiscount = (float) (clone $base)->sum('discount');
        $count = (int) (clone $base)->count();

        $cashSales = (float) Sale::query()
            ->completed()
            ->where(function ($q) {
                $q->where('payment_method', 'efectivo')
                    ->orWhere('payment_method', 'cash');
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            })
            ->sum('total');

        $cardSales = (float) Sale::query()
            ->completed()
            ->where(function ($q) {
                $q->where('payment_method', 'tarjeta')
                    ->orWhere('payment_method', 'card');
            })
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            })
            ->sum('total');

        return [
            'total' => $totalSales,
            'tax' => $totalTax,
            'discount' => $totalDiscount,
            'count' => $count,
            'cash' => $cashSales,
            'card' => $cardSales,
            'average' => $count > 0 ? $totalSales / $count : 0,
        ];
    }

    /**
     * @return list<array{product_name: string, total_quantity: float|int|string, total_revenue: float|int|string}>
     */
    public function getTopProductsProperty(): array
    {
        [$from, $to] = $this->period();

        return SaleItem::query()
            ->select(
                'product_name',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(subtotal) as total_revenue')
            )
            ->whereHas('sale', function ($query) use ($from, $to) {
                $query->completed()
                    ->where(function ($q) use ($from, $to) {
                        $q->whereBetween('sale_date', [$from, $to])
                            ->orWhereBetween('sold_at', [$from, $to]);
                    });
            })
            ->groupBy('product_name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) $row->product_name,
                'total_quantity' => $row->total_quantity,
                'total_revenue' => (float) $row->total_revenue,
            ])
            ->all();
    }

    public function getRetailVariantSummaryProperty(): array
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business || ! $tenant->isRetailStore()) {
            return ['variants' => [], 'sizes' => [], 'colors' => [], 'styles' => []];
        }

        [$from, $to] = $this->period();
        $rows = $this->retailSaleItems($from, $to);

        $variantMap = [];
        $sizeMap = [];
        $colorMap = [];
        $styleMap = [];

        foreach ($rows as $row) {
            $variant = $row->variant;
            $attributes = is_array($variant?->attributes) ? $variant->attributes : [];
            $size = trim((string) ($attributes['size'] ?? 'N/D')) ?: 'N/D';
            $color = trim((string) ($attributes['color'] ?? 'N/D')) ?: 'N/D';
            $style = trim((string) ($row->product?->style ?? $attributes['style'] ?? 'N/D')) ?: 'N/D';
            $label = trim((string) ($variant?->label() ?? $row->product_name)) ?: 'Sin nombre';
            $qty = (float) $row->quantity;
            $revenue = (float) $row->subtotal;

            $variantMap[$label] = [
                'label' => $label,
                'quantity' => ($variantMap[$label]['quantity'] ?? 0.0) + $qty,
                'revenue' => ($variantMap[$label]['revenue'] ?? 0.0) + $revenue,
            ];
            $sizeMap[$size] = ['label' => $size, 'quantity' => ($sizeMap[$size]['quantity'] ?? 0.0) + $qty];
            $colorMap[$color] = ['label' => $color, 'quantity' => ($colorMap[$color]['quantity'] ?? 0.0) + $qty];
            $styleMap[$style] = ['label' => $style, 'quantity' => ($styleMap[$style]['quantity'] ?? 0.0) + $qty];
        }

        return [
            'variants' => collect($variantMap)->sortByDesc('quantity')->take(15)->values()->all(),
            'sizes' => collect($sizeMap)->sortByDesc('quantity')->values()->all(),
            'colors' => collect($colorMap)->sortByDesc('quantity')->values()->all(),
            'styles' => collect($styleMap)->sortByDesc('quantity')->values()->all(),
        ];
    }

    public function getRetailCollectionSeasonSummaryProperty(): array
    {
        $tenant = filament()->getTenant();
        if (! $tenant instanceof Business || ! $tenant->isRetailStore()) {
            return ['seasons' => [], 'collections' => [], 'styles' => [], 'matrix' => []];
        }

        [$from, $to] = $this->period();
        $rows = $this->retailSaleItems($from, $to);

        $seasons = [];
        $collections = [];
        $styles = [];
        $matrix = [];

        foreach ($rows as $row) {
            $qty = (float) $row->quantity;
            $revenue = (float) $row->subtotal;
            $season = trim((string) ($row->product?->season?->name ?? 'Sin temporada')) ?: 'Sin temporada';
            $collection = trim((string) ($row->product?->collection ?? 'Sin colección')) ?: 'Sin colección';
            $style = trim((string) ($row->product?->style ?? 'Sin estilo')) ?: 'Sin estilo';
            $matrixKey = $season.'|'.$collection;

            $seasons[$season] = [
                'label' => $season,
                'quantity' => ($seasons[$season]['quantity'] ?? 0.0) + $qty,
                'revenue' => ($seasons[$season]['revenue'] ?? 0.0) + $revenue,
            ];
            $collections[$collection] = [
                'label' => $collection,
                'quantity' => ($collections[$collection]['quantity'] ?? 0.0) + $qty,
                'revenue' => ($collections[$collection]['revenue'] ?? 0.0) + $revenue,
            ];
            $styles[$style] = [
                'label' => $style,
                'quantity' => ($styles[$style]['quantity'] ?? 0.0) + $qty,
                'revenue' => ($styles[$style]['revenue'] ?? 0.0) + $revenue,
            ];
            $matrix[$matrixKey] = [
                'season' => $season,
                'collection' => $collection,
                'quantity' => ($matrix[$matrixKey]['quantity'] ?? 0.0) + $qty,
                'revenue' => ($matrix[$matrixKey]['revenue'] ?? 0.0) + $revenue,
            ];
        }

        return [
            'seasons' => collect($seasons)->sortByDesc('quantity')->values()->all(),
            'collections' => collect($collections)->sortByDesc('quantity')->values()->all(),
            'styles' => collect($styles)->sortByDesc('quantity')->values()->all(),
            'matrix' => collect($matrix)->sortByDesc('quantity')->take(20)->values()->all(),
        ];
    }

    protected function retailSaleItems(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        return SaleItem::query()
            ->with([
                'variant:id,product_id,attributes,sku,barcode,stock',
                'product:id,name,collection,style,season_id',
                'product.season:id,name',
            ])
            ->whereNotNull('variant_id')
            ->whereHas('sale', function ($query) use ($from, $to) {
                $query->completed()
                    ->where(function ($q) use ($from, $to) {
                        $q->whereBetween('sale_date', [$from, $to])
                            ->orWhereBetween('sold_at', [$from, $to]);
                    });
            })
            ->get();
    }

    /**
     * @return array{revenue: float, cost: float, gross_profit: float, expenses: float, net_profit: float, margin: float}
     */
    public function getProfitSummaryProperty(): array
    {
        [$from, $to] = $this->period();

        $sales = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            })
            ->with('items')
            ->get();

        $totalRevenue = (float) $sales->sum('total');
        $totalCost = 0.0;

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $unitCost = (float) ($item->unit_cost ?? 0);
                // Fallback al costo del producto si la línea no tiene unit_cost
                if ($unitCost <= 0 && $item->product_id) {
                    $unitCost = (float) (Product::query()->whereKey($item->product_id)->value('cost') ?? 0);
                }
                $totalCost += $unitCost * (float) $item->quantity;
            }
        }

        $grossProfit = $totalRevenue - $totalCost;
        $expenses = (float) Expense::query()
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');
        $netProfit = $grossProfit - $expenses;

        return [
            'revenue' => $totalRevenue,
            'cost' => $totalCost,
            'gross_profit' => $grossProfit,
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'margin' => $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0,
        ];
    }

    /**
     * @return array{pending: float, overdue: float, count: int}
     */
    public function getReceivablesSummaryProperty(): array
    {
        $pending = (float) Receivable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->sum('balance');

        $overdue = (float) Receivable::query()
            ->where('balance', '>', 0)
            ->where(function ($q) {
                $q->where('status', 'vencida')
                    ->orWhere(function ($q2) {
                        $q2->where('status', '!=', 'pagada')
                            ->whereDate('due_date', '<', now()->toDateString());
                    });
            })
            ->sum('balance');

        $count = (int) Receivable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->count();

        return [
            'pending' => $pending,
            'overdue' => $overdue,
            'count' => $count,
        ];
    }

    /**
     * @return array{pending: float, overdue: float, count: int}
     */
    public function getPayablesSummaryProperty(): array
    {
        $pending = (float) Payable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->sum('balance');

        $overdue = (float) Payable::query()
            ->where('balance', '>', 0)
            ->where(function ($q) {
                $q->where('status', 'vencida')
                    ->orWhere(function ($q2) {
                        $q2->where('status', '!=', 'pagada')
                            ->whereDate('due_date', '<', now()->toDateString());
                    });
            })
            ->sum('balance');

        $count = (int) Payable::query()
            ->where('status', '!=', 'pagada')
            ->where('balance', '>', 0)
            ->count();

        return [
            'pending' => $pending,
            'overdue' => $overdue,
            'count' => $count,
        ];
    }

    /**
     * @return array{total_cost: float, total_price: float, potential_profit: float, low_stock: int, out_of_stock: int, total_products: int}
     */
    public function getInventoryValueProperty(): array
    {
        $products = Product::query()->where('is_active', true)->get();

        $totalCost = (float) $products->sum(fn (Product $p) => (float) $p->stock * (float) $p->cost);
        $totalPrice = (float) $products->sum(fn (Product $p) => (float) $p->stock * (float) $p->price);
        $lowStock = $products->filter(function (Product $p) {
            if (! $p->track_stock) {
                return false;
            }

            return (float) $p->stock > 0 && (float) $p->stock <= (float) $p->min_stock;
        })->count();
        $outOfStock = $products->filter(fn (Product $p) => $p->track_stock && (float) $p->stock <= 0)->count();

        return [
            'total_cost' => $totalCost,
            'total_price' => $totalPrice,
            'potential_profit' => $totalPrice - $totalCost,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
            'total_products' => $products->count(),
        ];
    }

    /**
     * Ventas agrupadas por día (detalle del período).
     *
     * @return list<array{date: string, total_sales: int|string, total_amount: float|string, total_tax: float|string}>
     */
    public function getSalesByDayProperty(): array
    {
        [$from, $to] = $this->period();

        return Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            })
            ->select(
                DB::raw('DATE(COALESCE(sale_date, sold_at, created_at)) as date'),
                DB::raw('COUNT(*) as total_sales'),
                DB::raw('COALESCE(SUM(total), 0) as total_amount'),
                DB::raw('COALESCE(SUM(tax), 0) as total_tax')
            )
            ->groupBy(DB::raw('DATE(COALESCE(sale_date, sold_at, created_at))'))
            ->orderByDesc('date')
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'total_sales' => (int) $row->total_sales,
                'total_amount' => (float) $row->total_amount,
                'total_tax' => (float) $row->total_tax,
            ])
            ->all();
    }

    /**
     * Resumen de sesiones de caja cerradas en el período.
     *
     * @return array{count: int, total_sales_cash: float, total_difference: float, sessions: list<array<string, mixed>>}
     */
    public function getCashSessionsSummaryProperty(): array
    {
        [$from, $to] = $this->period();

        $sessions = CashSession::query()
            ->with(['user:id,name', 'closedByUser:id,name'])
            ->where('status', 'closed')
            ->whereBetween('opened_at', [$from, $to])
            ->orderByDesc('opened_at')
            ->get();

        return [
            'count' => $sessions->count(),
            'total_sales_cash' => (float) $sessions->sum('total_sales_cash'),
            'total_difference' => (float) $sessions->sum('difference'),
            'sessions' => $sessions->take(10)->map(fn (CashSession $s) => [
                'opened_at' => $s->opened_at?->format('d/m/Y H:i') ?? '—',
                'closed_at' => $s->closed_at?->format('d/m/Y H:i') ?? '—',
                'user' => $s->user?->name ?? '—',
                'opening_amount' => (float) $s->opening_amount,
                'total_sales_cash' => (float) $s->total_sales_cash,
                'expected_amount' => (float) ($s->expected_amount ?? 0),
                'closing_amount' => $s->closing_amount !== null ? (float) $s->closing_amount : null,
                'difference' => $s->difference !== null ? (float) $s->difference : null,
            ])->all(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function period(): array
    {
        try {
            $from = Carbon::parse($this->date_from ?: now()->startOfMonth())->startOfDay();
        } catch (\Throwable) {
            $from = now()->startOfMonth()->startOfDay();
        }

        try {
            $to = Carbon::parse($this->date_to ?: now())->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }

        if ($from->gt($to)) {
            return [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }

        if (! $tenant->hasModule('reports')) {
            return false;
        }

        // Tabla: owner, admin, supervisor
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
