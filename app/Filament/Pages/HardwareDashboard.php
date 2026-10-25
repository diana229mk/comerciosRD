<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\InventoryAlertService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HardwareDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationLabel = 'Dashboard Ferretería';
    protected static ?string $title = 'Dashboard Ferretería';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.hardware-dashboard';
    protected static ?string $slug = 'dashboard-ferreteria';

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->isHardwareStore()) return false;
        return \App\Support\DashboardAccess::allowed();
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) return [];
        $bid = $tenant->id;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        // Ventas hoy y mes
        $salesToday = (float) Sale::query()->where('business_id', $bid)
            ->whereDate('sale_date', $today)->where('status', '!=', 'anulada')->sum('total');
        $salesMonth = (float) Sale::query()->where('business_id', $bid)
            ->where('sale_date', '>=', $monthStart)->where('status', '!=', 'anulada')->sum('total');
        $countToday = Sale::query()->where('business_id', $bid)
            ->whereDate('sale_date', $today)->where('status', '!=', 'anulada')->count();

        // Margen del mes (precio - costo × qty en sale_items)
        $margin = (float) SaleItem::query()->where('business_id', $bid)
            ->whereHas('sale', fn ($q) => $q->where('sale_date', '>=', $monthStart)->where('status', '!=', 'anulada'))
            ->selectRaw('COALESCE(SUM((unit_price - COALESCE(unit_cost, 0)) * quantity), 0) as m')
            ->value('m');

        // Top productos del mes (top 5)
        $topProducts = SaleItem::query()->where('business_id', $bid)
            ->whereHas('sale', fn ($q) => $q->where('sale_date', '>=', $monthStart)->where('status', '!=', 'anulada'))
            ->select('product_id', 'product_name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(subtotal) as total'))
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('qty')
            ->limit(5)->get();

        // Alertas
        $alertSvc = app(InventoryAlertService::class);
        $low = $alertSvc->lowStock($bid)->count();
        $over = $alertSvc->overStock($bid)->count();
        $dead = $alertSvc->deadStock($bid)->count();

        // CxC aging (si módulo receivables activo)
        $aging = ['due_0_30' => 0.0, 'due_31_60' => 0.0, 'due_61_90' => 0.0, 'due_90_plus' => 0.0];
        if (class_exists(Receivable::class)) {
            $rec = Receivable::query()->where('business_id', $bid)->where('status', '!=', 'pagada')->get();
            foreach ($rec as $r) {
                $days = $r->due_date ? Carbon::parse($r->due_date)->diffInDays(now(), false) : 0;
                $bal = (float) ($r->amount ?? 0) - (float) ($r->paid_amount ?? 0);
                if ($bal <= 0) continue;
                if ($days <= 30) $aging['due_0_30'] += $bal;
                elseif ($days <= 60) $aging['due_31_60'] += $bal;
                elseif ($days <= 90) $aging['due_61_90'] += $bal;
                else $aging['due_90_plus'] += $bal;
            }
        }

        // Valor total inventario (stock × cost)
        $invValue = (float) Product::query()->where('business_id', $bid)
            ->where('is_active', true)
            ->selectRaw('COALESCE(SUM(stock * cost), 0) as v')
            ->value('v');

        return compact('salesToday', 'salesMonth', 'countToday', 'margin', 'topProducts',
            'low', 'over', 'dead', 'aging', 'invValue');
    }
}
