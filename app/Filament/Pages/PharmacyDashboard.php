<?php

namespace App\Filament\Pages;

use App\Models\ControlledDrugLedgerEntry;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Sale;
use App\Models\SaleItem;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class PharmacyDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-pie';
    protected static ?string $navigationLabel = 'Dashboard Farmacia';
    protected static ?string $title = 'Dashboard Farmacia';
    protected static ?string $navigationGroup = 'Farmacia';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.pharmacy-dashboard';

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        if (! $tenant || ! method_exists($tenant, 'isPharmacy') || ! $tenant->isPharmacy()) {
            return false;
        }

        return \App\Support\DashboardAccess::allowed();
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }
        $bid = $tenant->id;
        $nearDays = method_exists($tenant, 'pharmacyNearExpiryDays') ? $tenant->pharmacyNearExpiryDays() : 30;

        // Vencimientos
        $expired = ProductBatch::query()->where('business_id', $bid)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', now()->toDateString())
            ->count();
        $nearExpiry = ProductBatch::query()->where('business_id', $bid)
            ->where('quantity', '>', 0)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays($nearDays)->toDateString()])
            ->count();

        // Stock bajo
        $lowStock = Product::query()->where('business_id', $bid)
            ->where('is_active', true)
            ->whereRaw('stock <= COALESCE(reorder_point, min_stock, 0)')
            ->where(function ($q) {
                $q->where('min_stock', '>', 0)->orWhere('reorder_point', '>', 0);
            })->count();
        $outOfStock = Product::query()->where('business_id', $bid)
            ->where('is_active', true)->where('stock', '<=', 0)->count();

        // Ventas hoy y mes
        $today = Sale::query()->where('business_id', $bid)
            ->whereDate('sale_date', now()->toDateString())->sum('total');
        $monthStart = now()->startOfMonth();
        $month = Sale::query()->where('business_id', $bid)
            ->where('sale_date', '>=', $monthStart)->sum('total');
        $countToday = Sale::query()->where('business_id', $bid)
            ->whereDate('sale_date', now()->toDateString())->count();

        // Top 10 vendidos (últimos 30 días)
        $top = SaleItem::query()
            ->select('sale_items.product_id', 'sale_items.product_name',
                DB::raw('SUM(sale_items.quantity) as qty'),
                DB::raw('SUM(sale_items.subtotal) as total'))
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $bid)
            ->where('sales.sale_date', '>=', now()->subDays(30))
            ->whereNull('sales.deleted_at')
            ->groupBy('sale_items.product_id', 'sale_items.product_name')
            ->orderByDesc('qty')
            ->limit(10)
            ->get();

        // Margen bruto últimos 30 días
        $marginRow = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $bid)
            ->where('sales.sale_date', '>=', now()->subDays(30))
            ->whereNull('sales.deleted_at')
            ->selectRaw('SUM(sale_items.subtotal) as revenue, SUM(sale_items.quantity * sale_items.unit_cost) as cost')
            ->first();
        $revenue = (float) ($marginRow->revenue ?? 0);
        $cost = (float) ($marginRow->cost ?? 0);
        $margin = $revenue - $cost;
        $marginPct = $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0;

        // Controlados últimos 30d
        $controlled = ControlledDrugLedgerEntry::query()
            ->where('business_id', $bid)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->count();

        return compact('expired', 'nearExpiry', 'lowStock', 'outOfStock',
            'today', 'month', 'countToday', 'top', 'revenue', 'cost', 'margin', 'marginPct', 'controlled');
    }
}
