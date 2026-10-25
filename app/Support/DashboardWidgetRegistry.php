<?php

namespace App\Support;

use App\Enums\BusinessType;
use App\Filament\Widgets\CriticalStockWidget;
use App\Filament\Widgets\CustomersWithDebt;
use App\Filament\Widgets\ExpiringProductsWidget;
use App\Filament\Widgets\ExpensesByCategoryChart;
use App\Filament\Widgets\InventoryOverview;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\LowStock;
use App\Filament\Widgets\OrdersPending;
use App\Filament\Widgets\PayablesDueSoon;
use App\Filament\Widgets\PayablesDueWidget;
use App\Filament\Widgets\PharmacyExpiryAlertsWidget;
use App\Filament\Widgets\PharmacyExpiringBatchesWidget;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\ReceivablesDueSoon;
use App\Filament\Widgets\ReceivablesPendingWidget;
use App\Filament\Widgets\RecentInventoryMovements;
use App\Filament\Widgets\RecentOrders;
use App\Filament\Widgets\SalesByHourChart;
use App\Filament\Widgets\SalesLast30DaysChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TopProducts;
use App\Filament\Widgets\TopProductsChart;
use App\Filament\Widgets\TopSellingProducts;

/**
 * Catálogo de widgets del Dashboard + defaults por tipo de negocio.
 */
final class DashboardWidgetRegistry
{
    public const KPI = 'kpis';

    public const QUICK_ACTIONS = 'quick_actions';

    public const CRITICAL_STOCK = 'critical_stock';

    public const PAYABLES_DUE = 'payables_due';

    public const RECEIVABLES = 'receivables';

    public const EXPIRING = 'expiring_products';

    public const PHARMACY_EXPIRY_ALERTS = 'pharmacy_expiry_alerts';

    public const SALES_30D = 'sales_30_days';

    public const TOP_PRODUCTS = 'top_products';

    public const EXPENSES_PIE = 'expenses_category';

    public const SALES_BY_HOUR = 'sales_by_hour';

    /**
     * Etiquetas para el formulario de personalización.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::KPI => 'KPIs principales',
            self::QUICK_ACTIONS => 'Acciones rápidas',
            self::CRITICAL_STOCK => 'Stock crítico',
            self::PAYABLES_DUE => 'Cuentas por pagar (7 días)',
            self::RECEIVABLES => 'Cuentas por cobrar',
            self::EXPIRING => 'Productos por vencer',
            self::PHARMACY_EXPIRY_ALERTS => 'Alertas de vencimiento (farmacia)',
            self::SALES_30D => 'Ventas últimos 30 días',
            self::TOP_PRODUCTS => 'Top 10 productos',
            self::EXPENSES_PIE => 'Gastos por categoría',
            self::SALES_BY_HOUR => 'Ventas por horario',
        ];
    }

    /**
     * Mapa key → clase de widget Filament (personalizables).
     *
     * @return array<string, class-string>
     */
    public static function classMap(): array
    {
        return [
            self::KPI => KpiOverviewWidget::class,
            self::QUICK_ACTIONS => QuickActionsWidget::class,
            self::CRITICAL_STOCK => CriticalStockWidget::class,
            self::PAYABLES_DUE => PayablesDueWidget::class,
            self::RECEIVABLES => ReceivablesPendingWidget::class,
            self::EXPIRING => ExpiringProductsWidget::class,
            self::PHARMACY_EXPIRY_ALERTS => PharmacyExpiryAlertsWidget::class,
            self::SALES_30D => SalesLast30DaysChart::class,
            self::TOP_PRODUCTS => TopProductsChart::class,
            self::EXPENSES_PIE => ExpensesByCategoryChart::class,
            self::SALES_BY_HOUR => SalesByHourChart::class,
        ];
    }

    /**
     * Defaults de visibilidad según tipo de negocio (preferencias del dueño).
     *
     * @return array<string, bool>
     */
    public static function defaultsFor(?BusinessType $type): array
    {
        $all = array_fill_keys(array_keys(self::labels()), false);
        $all[self::KPI] = true;
        $all[self::QUICK_ACTIONS] = true;
        $all[self::CRITICAL_STOCK] = true;
        $all[self::SALES_30D] = true;
        $all[self::TOP_PRODUCTS] = true;
        $all[self::EXPENSES_PIE] = true;

        $key = $type?->value ?? BusinessType::General->value;

        return match ($key) {
            'colmado' => array_merge($all, [
                self::RECEIVABLES => true,
                self::SALES_BY_HOUR => true,
                self::PAYABLES_DUE => false,
                self::EXPIRING => false,
            ]),
            'farmacia' => array_merge($all, [
                self::EXPIRING => true,
                self::PHARMACY_EXPIRY_ALERTS => true,
                self::RECEIVABLES => true,
                self::SALES_BY_HOUR => true,
                self::PAYABLES_DUE => false,
            ]),
            'ferreteria' => array_merge($all, [
                self::PAYABLES_DUE => true,
                self::RECEIVABLES => true,
                self::SALES_BY_HOUR => false,
                self::EXPIRING => false,
            ]),
            'cafeteria' => array_merge($all, [
                self::SALES_BY_HOUR => true,
                self::RECEIVABLES => false,
                self::PAYABLES_DUE => false,
                self::EXPIRING => false,
            ]),
            'tienda' => array_merge($all, [
                self::RECEIVABLES => true,
                self::SALES_BY_HOUR => false,
                self::PAYABLES_DUE => false,
                self::EXPIRING => false,
            ]),
            'distribuidora', 'almacen' => array_merge($all, [
                self::RECEIVABLES => true,
                self::PAYABLES_DUE => true,
                self::SALES_BY_HOUR => false,
                self::EXPIRING => false,
            ]),
            default => array_merge($all, [
                self::RECEIVABLES => true,
                self::PAYABLES_DUE => false,
                self::EXPIRING => false,
                self::SALES_BY_HOUR => false,
            ]),
        };
    }

    /**
     * ¿El widget aplica al tipo de negocio? (filtro de dominio suave).
     * Los módulos del tenant siguen siendo la barrera real.
     */
    public static function appliesToBusiness(string $key, ?BusinessType $type): bool
    {
        return match ($key) {
            // Vencimientos: sobre todo farmacia (otros pueden activarlo en settings)
            self::EXPIRING => $type === null
                || $type === BusinessType::Farmacia
                || $type === BusinessType::General
                || $type === BusinessType::Colmado,

            // Alertas 30/60/90 son exclusivas de farmacia.
            self::PHARMACY_EXPIRY_ALERTS => $type === BusinessType::Farmacia,

            default => true,
        };
    }

    /**
     * Widgets del Dashboard principal según giro del negocio.
     * Solo widgets prioritarios (canView sigue filtrando por módulo).
     *
     * @return list<class-string>
     */
    public static function dashboardWidgetsFor(?BusinessType $type): array
    {
        // Comunes a todos
        $common = [
            StatsOverview::class,
            QuickActionsWidget::class,
        ];

        $key = $type?->value ?? BusinessType::General->value;

        $extra = match ($key) {
            'colmado' => [
                LowStock::class,
                CustomersWithDebt::class,
                ReceivablesDueSoon::class,
                TopSellingProducts::class,
                SalesByHourChart::class,
            ],
            'farmacia' => [
                PharmacyExpiryAlertsWidget::class,
                PharmacyExpiringBatchesWidget::class,
                ExpiringProductsWidget::class,
                LowStock::class,
                InventoryOverview::class,
                CustomersWithDebt::class,
                TopSellingProducts::class,
            ],
            'ferreteria' => [
                OrdersPending::class,
                LowStock::class,
                PayablesDueSoon::class,
                ReceivablesDueSoon::class,
                RecentInventoryMovements::class,
            ],
            'repuestos' => [
                OrdersPending::class,
                LowStock::class,
                ReceivablesDueSoon::class,
                PayablesDueSoon::class,
                TopSellingProducts::class,
                RecentInventoryMovements::class,
            ],
            'cafeteria' => [
                OrdersPending::class,
                RecentOrders::class,
                TopSellingProducts::class,
                TopProducts::class,
                SalesByHourChart::class,
                LowStock::class,
                InventoryOverview::class,
            ],
            'tienda' => [
                TopSellingProducts::class,
                TopProducts::class,
                LowStock::class,
                CustomersWithDebt::class,
                SalesLast30DaysChart::class,
            ],
            'distribuidora', 'almacen' => [
                OrdersPending::class,
                ReceivablesDueSoon::class,
                PayablesDueSoon::class,
                CustomersWithDebt::class,
                LowStock::class,
            ],
            default => [
                LowStock::class,
                TopSellingProducts::class,
                CustomersWithDebt::class,
            ],
        };

        return array_values(array_unique(array_merge($common, $extra)));
    }
}
