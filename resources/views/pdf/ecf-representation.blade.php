<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>e-CF {{ $invoice->ecf_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #222;
            padding: 20px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #111;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .header h1 {
            font-size: 16px;
            margin-bottom: 4px;
        }
        .header .subtitle {
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }
        .header .encf {
            font-size: 14px;
            font-weight: bold;
            margin-top: 6px;
            letter-spacing: 1px;
        }
        .meta {
            width: 100%;
            margin-bottom: 15px;
        }
        .meta td {
            vertical-align: top;
            padding: 3px 0;
        }
        .label {
            color: #666;
            font-size: 10px;
        }
        .value {
            font-weight: bold;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        table.items th {
            background: #f0f0f0;
            border: 1px solid #ccc;
            padding: 6px 4px;
            font-size: 10px;
            text-align: left;
        }
        table.items td {
            border: 1px solid #ddd;
            padding: 5px 4px;
            font-size: 10px;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .totals {
            width: 45%;
            margin-left: auto;
            margin-top: 10px;
        }
        .totals td {
            padding: 4px 8px;
        }
        .totals .total-row {
            font-size: 13px;
            font-weight: bold;
            border-top: 2px solid #111;
        }
        .footer {
            margin-top: 25px;
            border-top: 1px solid #ccc;
            padding-top: 12px;
            font-size: 9px;
            color: #555;
        }
        .qr-section {
            text-align: center;
            margin-top: 15px;
        }
        .qr-section img {
            width: 100px;
            height: 100px;
        }
        .status {
            display: inline-block;
            padding: 3px 10px;
            border: 1px solid #333;
            font-size: 10px;
            font-weight: bold;
            margin-top: 5px;
        }
        .note {
            margin-top: 12px;
            font-size: 9px;
            color: #777;
            text-align: center;
        }
    </style>
</head>
<body>
    @php
        $tipo = $invoice->ecf_type instanceof \App\Enums\EcfType
            ? $invoice->ecf_type->value . ' - ' . $invoice->ecf_type->label()
            : $invoice->ecf_type;

        $statusValue = $invoice->status instanceof \App\Enums\ElectronicInvoiceStatus
            ? $invoice->status->value
            : (string) $invoice->status;

        $statusLabel = match ($statusValue) {
            'draft' => 'BORRADOR',
            'issued' => 'EMITIDO',
            'sent' => 'ENVIADO DGII',
            'accepted' => 'ACEPTADO DGII',
            'rejected' => 'RECHAZADO',
            'cancelled' => 'ANULADO',
            default => strtoupper($statusValue),
        };
    @endphp

    <div class="header">
        <h1>{{ $invoice->issuer_name }}</h1>
        <div>RNC: {{ $invoice->issuer_rnc }}</div>
        @if($invoice->business?->address)
            <div>{{ $invoice->business->address }}</div>
        @endif
        @if($invoice->business?->phone)
            <div>Tel: {{ $invoice->business->phone }}</div>
        @endif

        <div class="subtitle" style="margin-top: 10px;">COMPROBANTE FISCAL ELECTRÓNICO</div>
        <div class="subtitle">{{ $tipo }}</div>
        <div class="encf">{{ $invoice->ecf_number }}</div>
        <div class="status">{{ $statusLabel }}</div>
    </div>

    <table class="meta">
        <tr>
            <td width="50%">
                <div class="label">Fecha de emisión</div>
                <div class="value">{{ $invoice->issue_date?->format('d/m/Y') }}</div>
            </td>
            <td width="50%">
                <div class="label">Cliente</div>
                <div class="value">{{ $invoice->buyer_name ?? 'CONSUMIDOR FINAL' }}</div>
                @if($invoice->buyer_rnc)
                    <div>RNC/Cédula: {{ $invoice->buyer_rnc }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="45%">Descripción</th>
                <th width="10%" class="text-center">Cant.</th>
                <th width="15%" class="text-right">Precio</th>
                <th width="10%" class="text-right">ITBIS</th>
                <th width="15%" class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $i => $item)
                <tr>
                    <td class="text-center">{{ $i + 1 }}</td>
                    <td>
                        {{ $item->product_name }}
                        @if($item->product_code)
                            <br><span style="color:#888;font-size:9px;">{{ $item->product_code }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $item->tax_amount, 2) }}</td>
                    <td class="text-right">{{ number_format((float) $item->total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Subtotal</td>
            <td class="text-right">RD$ {{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        @if((float) $invoice->discount > 0)
            <tr>
                <td>Descuento</td>
                <td class="text-right">RD$ {{ number_format((float) $invoice->discount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td>ITBIS (18%)</td>
            <td class="text-right">RD$ {{ number_format((float) $invoice->tax, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>TOTAL</td>
            <td class="text-right">RD$ {{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
    </table>

    <div class="qr-section">
        @if(!empty($qrBase64))
            <img src="data:image/png;base64,{{ $qrBase64 }}" alt="QR">
        @endif
        <div style="margin-top: 6px; font-size: 10px;">{{ $invoice->ecf_number }}</div>
    </div>

    <div class="footer">
        <div><strong>Representación impresa del Comprobante Fiscal Electrónico (e-CF)</strong></div>
        <div>Este documento es una representación del e-CF emitido. La validez fiscal depende de la aceptación por la DGII.</div>
        @if($invoice->track_id)
            <div>TrackId DGII: {{ $invoice->track_id }}</div>
        @endif
        <div class="note">
            Generado el {{ now()->format('d/m/Y H:i') }} — {{ $invoice->issuer_name }}
        </div>
    </div>
</body>
</html>
