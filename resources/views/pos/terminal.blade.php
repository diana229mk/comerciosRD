<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Caja — {{ $business->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/pos-terminal.css') }}?v={{ @filemtime(public_path('css/pos-terminal.css')) ?: time() }}">
    <script src="{{ asset('js/barcode-scanner.js') }}?v={{ @filemtime(public_path('js/barcode-scanner.js')) ?: time() }}" defer></script>
</head>
<body class="pos-page">
    <header class="pos-top">
        <strong>Caja · {{ $business->name }}</strong>
        <span>
            @if (! empty($posConfig['quotes_enabled']))
                <a href="{{ $posConfig['quotes_url'] ?? url('/admin/'.$business->slug.'/quotes') }}">Cotizaciones</a>
                &nbsp;·&nbsp;
            @endif
            @unless(auth()->user()?->isStrictCashier())
                <a href="{{ url('/admin/'.$business->slug) }}">Panel</a>
                &nbsp;·&nbsp;
            @endunless
            <form method="POST" action="{{ url('/admin/logout') }}" style="display:inline">
                @csrf
                <button type="submit" style="background:none;border:0;color:#fdba74;cursor:pointer">Salir</button>
            </form>
        </span>
    </header>
    <main class="pos-wrap">
        @include('pos._app', ['posConfig' => $posConfig])
    </main>
</body>
</html>
