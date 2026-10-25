<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR menú · {{ $business->name }}</title>
    <style>
        body { margin:0; font-family:system-ui,sans-serif; background:#fafaf9; color:#1c1917; }
        .sheet { max-width:28rem; margin:1.5rem auto; padding:2rem; background:#fff; border:1px solid #e7e5e4; border-radius:1rem; text-align:center; }
        h1 { margin:0 0 .35rem; font-size:1.5rem; }
        p { color:#78716c; margin:.25rem 0 1.25rem; }
        .qr { display:inline-block; padding:1rem; background:#fff; border:1px solid #e7e5e4; border-radius:.75rem; }
        .qr svg { width:16rem; height:16rem; display:block; }
        .url { margin-top:1rem; font-size:.8rem; word-break:break-all; color:#57534e; }
        .hint { margin-top:1.5rem; font-size:.85rem; }
        @media print {
            body { background:#fff; }
            .sheet { border:0; margin:0; box-shadow:none; }
            .no-print { display:none; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <h1>{{ $business->name }}</h1>
        <p>Escanea para ver el menú</p>
        <div class="qr">{!! $qrSvg !!}</div>
        <div class="url">{{ $url }}</div>
        <p class="hint no-print">
            <button type="button" onclick="window.print()">Imprimir cartel</button>
        </p>
    </div>
</body>
</html>
