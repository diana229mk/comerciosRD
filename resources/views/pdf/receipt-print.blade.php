<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Imprimir Ticket - {{ $documentNumber }}</title>
    <style>
        body {
            margin: 0;
            background: #e5e7eb;
            font-family: system-ui, sans-serif;
        }
        .toolbar {
            position: sticky;
            top: 0;
            background: #111827;
            color: #fff;
            padding: 10px 16px;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
        .toolbar a, .toolbar button {
            background: #f59e0b;
            color: #111;
            border: 0;
            border-radius: 8px;
            padding: 8px 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .toolbar a.secondary {
            background: #374151;
            color: #fff;
        }
        .sheet {
            max-width: 320px;
            margin: 20px auto;
            background: #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,.15);
            border-radius: 8px;
            overflow: hidden;
        }
        iframe, .content {
            width: 100%;
            border: 0;
            min-height: 70vh;
        }
        @media print {
            .toolbar { display: none !important; }
            body { background: #fff; }
            .sheet { box-shadow: none; margin: 0; max-width: none; border-radius: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar no-print">
        <button type="button" onclick="window.print()">Imprimir (Ctrl+P)</button>
        <a class="secondary" href="{{ $pdfUrl }}">Descargar PDF</a>
        <a class="secondary" href="javascript:window.close()">Cerrar</a>
    </div>
    <div class="sheet">
        <div class="content">
            @include('pdf.receipt', get_defined_vars())
        </div>
    </div>
    <script>
        // Opcional: auto-focus print dialog
        // window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    </script>
</body>
</html>
