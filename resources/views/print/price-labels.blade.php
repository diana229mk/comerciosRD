<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiquetas de precio</title>
    <style>
        * { box-sizing: border-box; }
        @page { margin: 6mm; }
        body { margin: 0; background: #fff; color: #111; font-family: Arial, Helvetica, sans-serif; }
        .toolbar { padding: 10px 12px; }
        .toolbar button { padding: 8px 14px; font-size: 14px; cursor: pointer; }
        .sheet { display: flex; flex-wrap: wrap; gap: 3mm; padding: 4mm; }
        .label {
            width: {{ $w }}mm;
            height: {{ $h }}mm;
            border: 1px solid #222;
            padding: 1.2mm 1.6mm;
            overflow: hidden;
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            gap: 0.6mm;
            background: #fff;
            page-break-inside: avoid;
        }
        .name {
            font-weight: 700;
            font-size: {{ $h >= 35 ? '9pt' : '7pt' }};
            line-height: 1.15;
            max-height: {{ $h >= 35 ? '8mm' : '5.5mm' }};
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .bc { min-height: 0; overflow: hidden; display: flex; align-items: center; }
        .bc svg { width: 100% !important; height: {{ $h >= 35 ? '11mm' : '7mm' }} !important; display: block; }
        .row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1mm;
            min-width: 0;
        }
        .sku {
            font-size: 6.5pt;
            font-family: ui-monospace, monospace;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .price {
            font-weight: 800;
            font-size: {{ $h >= 35 ? '12pt' : '9pt' }};
            white-space: nowrap;
            flex: 0 0 auto;
        }
        @media print {
            .toolbar { display: none !important; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Imprimir</button>
    </div>
    <div class="sheet">
        @foreach ($labels as $l)
            <div class="label">
                <div class="name">{{ $l['name'] }}</div>
                <div class="bc">{!! $l['barcode_svg'] !!}</div>
                <div class="row">
                    <span class="sku">{{ $l['code'] }}@if (! empty($l['stock_text'])) · {{ $l['stock_text'] }}@endif</span>
                    @if ($l['show_price'])
                        <span class="price">RD$ {{ number_format($l['price'], 2) }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
