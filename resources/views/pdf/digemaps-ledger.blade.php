<!doctype html>
<html><head><meta charset="utf-8">
<style>
    @page { size: letter landscape; margin: 10mm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; }
    h1 { font-size: 14px; margin: 0 0 4px; text-align: center; }
    h2 { font-size: 11px; margin: 8px 0 4px; padding: 2px 6px; background: #eee; border-left: 3px solid #333; }
    .header { text-align: center; margin-bottom: 8px; }
    .header .rnc { font-size: 10px; }
    .meta { display: table; width: 100%; margin: 4px 0; }
    .meta .cell { display: table-cell; font-size: 9px; padding: 1px 8px 1px 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 2px; }
    th, td { border: 1px solid #333; padding: 2px 4px; vertical-align: top; }
    th { background: #f0f0f0; font-size: 8px; text-transform: uppercase; }
    td.num { text-align: right; }
    .footer { margin-top: 10px; font-size: 8px; color: #555; }
    .sig { margin-top: 25px; display: table; width: 100%; }
    .sig .col { display: table-cell; text-align: center; width: 33%; }
    .sig .line { border-top: 1px solid #333; margin: 20px 10px 4px; }
</style></head>
<body>

<div class="header">
    <h1>LIBRO OFICIAL DE CONTROL DE MEDICAMENTOS CONTROLADOS</h1>
    <div>{{ $business->name }}</div>
    <div class="rnc">RNC: {{ $business->rnc ?? '—' }} · Dirección: {{ $business->address ?? '—' }}</div>
    <div>DIGEMAPS - Dirección General de Medicamentos, Alimentos y Productos Sanitarios</div>
</div>

<div class="meta">
    <div class="cell"><b>Período:</b> {{ $from }} al {{ $to }}</div>
    <div class="cell"><b>Anexo:</b> {{ $anexo ? strtoupper(str_replace('_',' ',$anexo)) : 'Todos' }}</div>
    <div class="cell"><b>Emitido:</b> {{ now()->format('d/m/Y H:i') }}</div>
</div>

@forelse ($grouped as $productId => $entries)
    @php
        $product = $products[$productId] ?? null;
        $anexoLabel = strtoupper(str_replace('_',' ', $product?->controlled_type ?? ''));
    @endphp
    <h2>
        {{ $product?->name ?? 'Producto eliminado' }}
        · SKU: {{ $product?->sku ?? '—' }}
        · {{ $anexoLabel }}
    </h2>
    <table>
        <thead>
            <tr>
                <th style="width: 60px;">Fecha</th>
                <th style="width: 40px;">Tipo</th>
                <th style="width: 40px;">Cantidad</th>
                <th style="width: 40px;">Saldo</th>
                <th style="width: 40px;">Lote</th>
                <th>Paciente</th>
                <th>Cédula</th>
                <th>Médico</th>
                <th>Exequátur</th>
                <th>Documento</th>
                <th>Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $e)
                @php
                    $type = $e->movement_type;
                    $isEntry = in_array($type, ['entrada','devolucion_entrada','ajuste_entrada']);
                    $sign = $isEntry ? '+' : '-';
                @endphp
                <tr>
                    <td>{{ $e->recorded_at?->format('d/m/Y H:i') }}</td>
                    <td>{{ str_replace('_', ' ', $type) }}</td>
                    <td class="num">{{ $sign }}{{ number_format((float) $e->quantity, 3) }}</td>
                    <td class="num"><b>{{ number_format((float) $e->balance_after, 3) }}</b></td>
                    <td>{{ $e->batch_id ? substr($e->batch_id, 0, 8) : '—' }}</td>
                    <td>{{ $e->patient_name ?? '—' }}</td>
                    <td>{{ $e->patient_document ?? '—' }}</td>
                    <td>{{ $e->doctor_name ?? '—' }}</td>
                    <td>{{ $e->doctor_exequatur ?? '—' }}</td>
                    <td>{{ $e->document_number ?? '—' }}</td>
                    <td>{{ $e->notes ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@empty
    <p>Sin movimientos en el período seleccionado.</p>
@endforelse

<div class="footer">
    Documento generado automáticamente por el sistema. Sujeto a verificación por la autoridad reguladora (DIGEMAPS).
</div>

<div class="sig">
    <div class="col"><div class="line"></div>Farmacéutico Responsable</div>
    <div class="col"><div class="line"></div>Regente</div>
    <div class="col"><div class="line"></div>Inspector DIGEMAPS</div>
</div>

</body></html>
