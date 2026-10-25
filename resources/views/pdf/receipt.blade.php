<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket - {{ $sale->sale_number ?? ($order->order_number ?? '') }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 8px 10px;
            width: 80mm;
            color: #000;
            line-height: 1.35;
        }
        .header {
            text-align: center;
            margin-bottom: 8px;
        }
        .business-name {
            font-size: 15px;
            font-weight: bold;
            line-height: 1.2;
        }
        .business-info {
            font-size: 10px;
            line-height: 1.3;
        }
        hr {
            border: none;
            border-top: 1px dashed #000;
            margin: 6px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        th, td {
            padding: 3px 0;
            text-align: left;
        }
        .text-right {
            text-align: right;
        }
        .total-row {
            font-weight: bold;
            font-size: 13px;
        }
        .barcode {
            text-align: center;
            margin: 8px 0 4px;
        }
        .footer {
            text-align: center;
            margin-top: 10px;
            font-size: 10px;
        }
        .small {
            font-size: 9px;
        }
    </style>
</head>
<body>
@php
    $documentNumber = $sale->sale_number
        ?? ($order->order_number ?? null)
        ?? ($sale->number ?? null)
        ?? '000000';

    $documentDate = $sale->sale_date
        ?? ($order->created_at ?? null)
        ?? ($sale->created_at ?? null);

    $items = $sale->items ?? ($order->items ?? collect());
@endphp

    {{-- ENCABEZADO --}}
    <div class="header">
        @if(!empty($business->logo))
            <img src="{{ storage_path('app/public/' . $business->logo) }}" alt="Logo" style="max-height: 45px; margin-bottom: 4px;">
        @endif

        <div class="business-name">{{ $business->name ?? 'Comercio' }}</div>

        @if($business->address ?? null)
            <div class="business-info">{{ $business->address }}</div>
        @endif

        @if($business->phone ?? null)
            <div class="business-info">Tel: {{ $business->phone }}</div>
        @endif

        @if($business->rnc ?? null)
            <div class="business-info">RNC: {{ $business->rnc }}</div>
        @endif

        <hr>

        <strong>TICKET DE VENTA</strong><br>
        <span style="font-size: 12px; font-weight: bold;">{{ $documentNumber }}</span><br>
        <span class="small">
            {{ $documentDate ? \Illuminate\Support\Carbon::parse($documentDate)->format('d/m/Y H:i') : '' }}
        </span>
    </div>

    {{-- CÓDIGO DE BARRAS --}}
    <div class="barcode">
        @php
            $barcodeHtml = null;
            try {
                // Solo caracteres seguros para Code 128; limpia guiones raros
                $barcodeValue = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $documentNumber) ?: '000000';
                $generator = new \Picqer\Barcode\BarcodeGeneratorPNG();
                $barcodePng = $generator->getBarcode(
                    $barcodeValue,
                    $generator::TYPE_CODE_128,
                    2,
                    40
                );
                $barcodeHtml = base64_encode($barcodePng);
            } catch (\Throwable $e) {
                $barcodeHtml = null;
            }
        @endphp

        @if($barcodeHtml)
            <img src="data:image/png;base64,{{ $barcodeHtml }}" alt="Barcode" style="max-width: 100%; height: 40px;">
            <div class="small" style="margin-top: 2px;">{{ $documentNumber }}</div>
        @endif
    </div>

    <hr>

    {{-- PRODUCTOS --}}
    <table>
        <thead>
            <tr>
                <th style="width: 45%;">Producto</th>
                <th class="text-right" style="width: 15%;">Cant.</th>
                <th class="text-right" style="width: 20%;">Precio</th>
                <th class="text-right" style="width: 20%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">${{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-right">${{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
                @php
                    $batches = $batchesMap[$item->id] ?? null;
                @endphp
                @if($business?->isPharmacy() && $batches)
                    <tr>
                        <td colspan="4" class="small">
                            @foreach($batches as $b)
                                <div>Lote: {{ $b['code'] ?? '—' }} @if(!empty($b['expires_at'])) — Vence: {{ \Illuminate\Support\Carbon::parse($b['expires_at'])->format('d/m/Y') }}@endif (x{{ $b['quantity'] }})</div>
                            @endforeach
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <hr>

    {{-- TOTALES --}}
    <table>
        <tr>
            <td><strong>Subtotal:</strong></td>
            <td class="text-right">${{ number_format((float) ($sale->subtotal ?? $order->subtotal ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td><strong>ITBIS (18%):</strong></td>
            <td class="text-right">${{ number_format((float) ($sale->tax ?? $order->tax ?? 0), 2) }}</td>
        </tr>
        @if(isset($sale) && ($sale->discount ?? 0) > 0)
        <tr>
            <td><strong>Descuento:</strong></td>
            <td class="text-right">-${{ number_format((float) $sale->discount, 2) }}</td>
        </tr>
        @endif
        <tr class="total-row">
            <td><strong>TOTAL A PAGAR:</strong></td>
            <td class="text-right"><strong>${{ number_format((float) ($sale->total ?? $order->total ?? 0), 2) }}</strong></td>
        </tr>
        @if(isset($sale))
        <tr>
            <td><strong>Pagado:</strong></td>
            <td class="text-right">${{ number_format((float) ($sale->amount_paid ?? 0), 2) }}</td>
        </tr>
        @if(($sale->change ?? 0) > 0)
        <tr>
            <td><strong>Cambio:</strong></td>
            <td class="text-right">${{ number_format((float) $sale->change, 2) }}</td>
        </tr>
        @endif
        @endif
    </table>

    <hr>

    @if(isset($sale))
        <div style="margin: 6px 0;">
            <strong>Método de Pago:</strong>
            {{ ucfirst($sale->payment_method instanceof \BackedEnum ? $sale->payment_method->value : (string) $sale->payment_method) }}
        </div>

        @if($sale->notes ?? null)
            <div style="margin-top: 6px;">
                <strong>Notas:</strong> {{ $sale->notes }}
            </div>
        @endif
    @endif

    {{-- PIE DE PÁGINA --}}
    <div class="footer">
        <strong>¡Gracias por su compra!</strong><br>
        <span class="small">Este documento no tiene validez fiscal</span><br>
        <span class="small">Generado por ComerciosRD</span>
    </div>

</body>
</html>
