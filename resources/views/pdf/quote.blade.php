<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cotización {{ $quote->code }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; margin: 20px; }
    h1 { font-size: 18px; margin: 0 0 6px 0; }
    .muted { color: #777; }
    .row { display: block; margin-bottom: 6px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 5px 6px; border-bottom: 1px solid #ddd; }
    th { background: #f5f5f5; text-align: left; }
    .right { text-align: right; }
    .totals { margin-top: 10px; float: right; }
    .totals td { padding: 3px 8px; }
    .footer { margin-top: 30px; font-size: 10px; color: #666; }
    .valid { color: #b00; font-weight: bold; }
</style>
</head>
<body>
    <table style="width:100%; border: none; margin-bottom: 10px;">
        <tr style="border:none;">
            <td style="border:none;">
                <h1>{{ $quote->business->name ?? '' }}</h1>
                <div class="muted">RNC: {{ $quote->business->rnc ?? '-' }}</div>
                <div class="muted">{{ $quote->business->address ?? '' }}</div>
                <div class="muted">Tel: {{ $quote->business->phone ?? '-' }}</div>
            </td>
            <td style="border:none; text-align:right;">
                <h1>COTIZACIÓN</h1>
                <div><strong>{{ $quote->code }}</strong></div>
                <div class="muted">Fecha: {{ $quote->issue_date?->format('d/m/Y') }}</div>
                <div class="valid">Válida hasta: {{ $quote->valid_until?->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="row">
        <strong>Cliente:</strong> {{ $quote->customer?->name ?? $quote->customer_name ?? 'Consumidor Final' }}
        @if ($quote->customer?->document)
            &nbsp;|&nbsp; Doc: {{ $quote->customer->document }}
        @endif
    </div>
    @if ($quote->priceList)
        <div class="row muted">Lista de precios: {{ $quote->priceList->name }}</div>
    @endif

    @php
        $isRepuestos = ($quote->business?->typeKey() ?? $quote->business?->type ?? null) === 'repuestos';
    @endphp

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Descripción</th>
                @if ($isRepuestos)
                    <th>OEM</th>
                @endif
                <th>SKU</th>
                <th class="right">Cant.</th>
                <th>Unidad</th>
                <th class="right">P. Unit</th>
                <th class="right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $i => $it)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $it->product_name }}</td>
                    @if ($isRepuestos)
                        <td>{{ $it->oem_code ?? $it->product?->oem_code ?? '—' }}</td>
                    @endif
                    <td>{{ $it->product?->sku ?? '' }}</td>
                    <td class="right">{{ number_format((float) $it->quantity, 3) }}</td>
                    <td>{{ $it->unit_code ?? 'un' }}</td>
                    <td class="right">{{ number_format((float) $it->unit_price, 2) }}</td>
                    <td class="right">{{ number_format((float) $it->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal:</td>
            <td class="right">RD$ {{ number_format((float) $quote->subtotal, 2) }}</td>
        </tr>
        @if ((float) $quote->discount > 0)
            <tr>
                <td>Descuento:</td>
                <td class="right">−RD$ {{ number_format((float) $quote->discount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>ITBIS:</td>
            <td class="right">RD$ {{ number_format((float) $quote->tax, 2) }}</td>
        </tr>
        <tr>
            <td><strong>Total:</strong></td>
            <td class="right"><strong>RD$ {{ number_format((float) $quote->total, 2) }}</strong></td>
        </tr>
    </table>

    <div style="clear: both;"></div>

    @if ($quote->notes)
        <div class="row" style="margin-top: 20px;">
            <strong>Notas:</strong>
            <div>{{ $quote->notes }}</div>
        </div>
    @endif

    <div class="footer">
        <p>Esta cotización no constituye una factura. Los precios están sujetos a disponibilidad
        y son válidos hasta la fecha indicada.</p>
    </div>
</body>
</html>
