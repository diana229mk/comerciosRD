<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización {{ $order->quote_number ?: $order->order_number }}</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; margin: 0; padding: 24px; color: #222; }
        .header { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { margin: 0 0 4px; font-size: 22px; }
        .meta { width: 100%; margin-bottom: 18px; }
        .meta td { vertical-align: top; padding: 4px 6px 4px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th, table.items td { border: 1px solid #d0d0d0; padding: 6px; text-align: left; }
        table.items th { background: #f2f2f2; }
        .right { text-align: right; }
        .totals { width: 42%; margin-left: auto; border-collapse: collapse; margin-top: 12px; }
        .totals td { padding: 5px 8px; border-top: 1px solid #ddd; }
        .totals .grand { font-weight: bold; font-size: 14px; border-top: 2px solid #111; }
        .footer { margin-top: 20px; font-size: 10px; color: #555; border-top: 1px solid #ccc; padding-top: 8px; }
    </style>
</head>
<body>
    @php
        $isRepuestos = ! empty($order->quote_number) || ($business?->business_type === 'repuestos');
    @endphp

    <div class="header">
        <h1>{{ $business->name ?? 'Comercio' }}</h1>
        <div><strong>COTIZACIÓN</strong> {{ $order->quote_number ?: $order->order_number }}</div>
        @if($business?->rnc)
            <div>RNC: {{ $business->rnc }}</div>
        @endif
    </div>

    <table class="meta">
        <tr>
            <td width="50%">
                <strong>Cliente / Taller</strong><br>
                {{ $order->customer_name ?: ($order->customer?->name ?? '—') }}
            </td>
            <td width="50%" class="right">
                <strong>Fecha:</strong> {{ $order->created_at?->format('d/m/Y') }}<br>
                <strong>Válida hasta:</strong> {{ $order->valid_until?->format('d/m/Y') ?? '—' }}
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th width="8%">Cant.</th>
                <th>Descripción</th>
                @if($isRepuestos)
                    <th width="18%">OEM</th>
                @endif
                <th width="15%" class="right">Precio</th>
                <th width="15%" class="right">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ number_format((float) ($item->quantity ?? 0), 2) }}</td>
                    <td>{{ $item->product_name }}</td>
                    @if($isRepuestos)
                        <td>{{ $item->oem_code ?? $item->product?->oem_code ?? '—' }}</td>
                    @endif
                    <td class="right">RD$ {{ number_format((float) ($item->unit_price ?? 0), 2) }}</td>
                    <td class="right">RD$ {{ number_format((float) (($item->subtotal ?? 0) ?: ((float) ($item->quantity ?? 0) * (float) ($item->unit_price ?? 0))), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="right">RD$ {{ number_format((float) ($order->subtotal ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>ITBIS</td>
            <td class="right">RD$ {{ number_format((float) ($order->tax ?? 0), 2) }}</td>
        </tr>
        <tr class="grand">
            <td>Total</td>
            <td class="right">RD$ {{ number_format((float) ($order->total ?? 0), 2) }}</td>
        </tr>
    </table>

    @if($order->notes)
        <div class="footer">
            <strong>Notas:</strong> {{ $order->notes }}
        </div>
    @endif

    <div class="footer">
        Cotización válida hasta la fecha indicada. Precios sujetos a disponibilidad.
    </div>
</body>
</html>
