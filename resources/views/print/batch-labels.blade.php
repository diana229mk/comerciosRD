<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiquetas de lotes</title>
    <style>
        @page { size: A4; margin: 8mm; }
        body { font-family: Arial, sans-serif; margin: 0; }
        .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
        .label { border: 1px dashed #999; padding: 6px; text-align: center; page-break-inside: avoid; }
        .label .name { font-weight: 700; font-size: 12px; line-height: 1.1; }
        .label .sku { font-size: 10px; color: #666; }
        .label .price { font-size: 16px; font-weight: 700; margin: 2px 0; }
        .label .batch { font-size: 10px; color: #333; }
        .label .expires { font-size: 10px; color: #a33; }
        .label svg { display: block; margin: 4px auto; }
        .print-btn { padding: 8px 14px; margin: 8px; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <button class="print-btn" onclick="window.print()">Imprimir</button>
    <div class="grid">
        @foreach ($labels as $l)
            <div class="label">
                <div class="name">{{ $l['product'] }}</div>
                <div class="sku">{{ $l['sku'] }}</div>
                <div class="price">RD$ {{ number_format($l['price'], 2) }}</div>
                {!! $l['barcode_svg'] !!}
                <div class="batch">Lote: {{ $l['batch'] }}</div>
                @if ($l['expires'])<div class="expires">Vence: {{ $l['expires'] }}</div>@endif
            </div>
        @endforeach
    </div>
</body>
</html>
