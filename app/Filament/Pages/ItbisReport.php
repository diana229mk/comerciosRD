<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ItbisReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'Reporte de ITBIS';

    protected static ?string $title = 'Reporte de ITBIS';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.itbis-report';

    protected static ?string $slug = 'reporte-itbis';

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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Período')
                    ->schema([
                        Forms\Components\DatePicker::make('date_from')
                            ->label('Desde')
                            ->required()
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state): void {
                                $this->date_from = $state ?: $this->date_from;
                            }),

                        Forms\Components\DatePicker::make('date_to')
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
            ->statePath(null);
    }

    /**
     * @return array{
     *     itbis_cobrado: float,
     *     itbis_pagado: float,
     *     saldo: float,
     *     ventas_netas: float,
     *     ventas_totales: float,
     *     compras_netas: float,
     *     compras_totales: float,
     *     a_favor: bool,
     * }
     */
    public function getItbisSummaryProperty(): array
    {
        [$from, $to] = $this->period();

        $salesBase = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            });

        $itbisCobrado = (float) (clone $salesBase)->sum('tax');
        $ventasNetas = (float) (clone $salesBase)->sum('subtotal');
        $ventasTotales = (float) (clone $salesBase)->sum('total');

        $purchasesBase = Purchase::query()
            ->received()
            ->whereBetween('purchase_date', [$from->toDateString(), $to->toDateString()]);

        $itbisPagado = (float) (clone $purchasesBase)->sum('tax');
        $comprasNetas = (float) (clone $purchasesBase)->sum('subtotal');
        $comprasTotales = (float) (clone $purchasesBase)->sum('total');

        $saldo = round($itbisCobrado - $itbisPagado, 2);

        return [
            'itbis_cobrado' => round($itbisCobrado, 2),
            'itbis_pagado' => round($itbisPagado, 2),
            'saldo' => $saldo,
            'ventas_netas' => round($ventasNetas, 2),
            'ventas_totales' => round($ventasTotales, 2),
            'compras_netas' => round($comprasNetas, 2),
            'compras_totales' => round($comprasTotales, 2),
            'a_favor' => $saldo < 0,
        ];
    }

    /**
     * @return list<array{fecha: string, base: float, itbis: float, total: float, cantidad: int}>
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
                DB::raw('DATE(COALESCE(sale_date, sold_at, created_at)) as fecha'),
                DB::raw('SUM(subtotal) as base'),
                DB::raw('SUM(tax) as itbis'),
                DB::raw('SUM(total) as total'),
                DB::raw('COUNT(*) as cantidad')
            )
            ->groupBy(DB::raw('DATE(COALESCE(sale_date, sold_at, created_at))'))
            ->orderBy('fecha')
            ->get()
            ->map(fn ($row) => [
                'fecha' => (string) $row->fecha,
                'base' => (float) $row->base,
                'itbis' => (float) $row->itbis,
                'total' => (float) $row->total,
                'cantidad' => (int) $row->cantidad,
            ])
            ->all();
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

        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
