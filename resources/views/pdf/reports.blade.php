<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte - {{ $date_from }} a {{ $date_to }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
        h1 { font-size: 18px; margin-bottom: 5px; }
        h2 { font-size: 14px; margin-top: 20px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
        .header { text-align: center; margin-bottom: 20px; }
        .meta { text-align: center; color: #666; margin-bottom: 25px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
        th { background: #f5f5f5; }
        .text-right { text-align: right; }
        .grid { width: 100%; }
        .grid td { border: none; padding: 4px 8px; }
        .label { color: #666; }
        .value { font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $business->name ?? 'Comercio' }}</h1>
        <div class="meta">
            Reporte del {{ \Carbon\Carbon::parse($date_from)->format('d/m/Y') }}
            al {{ \Carbon\Carbon::parse($date_to)->format('d/m/Y') }}
        </div>
    </div>

    <h2>Resumen de Ventas</h2>
    <table class="grid">
        <tr>
            <td class="label">Total Ventas:</td>
            <td class="value">RD$ {{ number_format($salesSummary['total'] ?? 0, 2) }}</td>
            <td class="label">Cantidad:</td>
            <td class="value">{{ $salesSummary['count'] ?? 0 }}</td>
        </tr>
        <tr>
            <td class="label">Ticket Promedio:</td>
            <td class="value">RD$ {{ number_format($salesSummary['average'] ?? 0, 2) }}</td>
            <td class="label">ITBIS:</td>
            <td class="value">RD$ {{ number_format($salesSummary['tax'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Efectivo:</td>
            <td class="value">RD$ {{ number_format($salesSummary['cash'] ?? 0, 2) }}</td>
            <td class="label">Tarjeta:</td>
            <td class="value">RD$ {{ number_format($salesSummary['card'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Descuentos:</td>
            <td class="value">RD$ {{ number_format($salesSummary['discount'] ?? 0, 2) }}</td>
            <td class="label"></td>
            <td class="value"></td>
        </tr>
    </table>

    <h2>Ganancias</h2>
    <table class="grid">
        <tr>
            <td class="label">Ingresos:</td>
            <td class="value">RD$ {{ number_format($profitSummary['revenue'] ?? 0, 2) }}</td>
            <td class="label">Costo:</td>
            <td class="value">RD$ {{ number_format($profitSummary['cost'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Ganancia Bruta:</td>
            <td class="value">RD$ {{ number_format($profitSummary['gross_profit'] ?? 0, 2) }}</td>
            <td class="label">Gastos:</td>
            <td class="value">RD$ {{ number_format($profitSummary['expenses'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Ganancia Neta:</td>
            <td class="value">RD$ {{ number_format($profitSummary['net_profit'] ?? 0, 2) }}</td>
            <td class="label">Margen:</td>
            <td class="value">{{ number_format($profitSummary['margin'] ?? 0, 1) }}%</td>
        </tr>
    </table>

    <h2>Top Productos Más Vendidos</h2>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Producto</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Ingresos</th>
            </tr>
        </thead>
        <tbody>
            @forelse($topProducts as $i => $product)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $product['product_name'] ?? $product['name'] ?? '—' }}</td>
                    <td class="text-right">{{ $product['total_quantity'] ?? $product['qty'] ?? 0 }}</td>
                    <td class="text-right">RD$ {{ number_format((float) ($product['total_revenue'] ?? $product['revenue'] ?? 0), 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align:center;">No hay datos</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2>Cuentas por Cobrar / Pagar</h2>
    <table class="grid">
        <tr>
            <td class="label">CxC Pendiente:</td>
            <td class="value">RD$ {{ number_format($receivablesSummary['pending'] ?? 0, 2) }}</td>
            <td class="label">CxC Vencidas:</td>
            <td class="value">RD$ {{ number_format($receivablesSummary['overdue'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">CxP Pendiente:</td>
            <td class="value">RD$ {{ number_format($payablesSummary['pending'] ?? 0, 2) }}</td>
            <td class="label">CxP Vencidas:</td>
            <td class="value">RD$ {{ number_format($payablesSummary['overdue'] ?? 0, 2) }}</td>
        </tr>
    </table>

    <h2>Inventario</h2>
    <table class="grid">
        <tr>
            <td class="label">Valor al Costo:</td>
            <td class="value">RD$ {{ number_format($inventoryValue['total_cost'] ?? 0, 2) }}</td>
            <td class="label">Valor a Precio:</td>
            <td class="value">RD$ {{ number_format($inventoryValue['total_price'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Stock Bajo:</td>
            <td class="value">{{ $inventoryValue['low_stock'] ?? 0 }}</td>
            <td class="label">Sin Stock:</td>
            <td class="value">{{ $inventoryValue['out_of_stock'] ?? 0 }}</td>
        </tr>
    </table>

    <p style="margin-top: 30px; text-align: center; color: #999; font-size: 10px;">
        Generado el {{ now()->format('d/m/Y H:i') }} — {{ $business->name ?? 'ComerciosRD' }}
    </p>
</body>
</html>
