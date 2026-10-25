<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verificación 2FA — ComerciosRD</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #1e293b; border-radius: 16px; padding: 2rem; max-width: 400px; width: 100%; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: #94a3b8; font-size: .9rem; }
        input { width: 100%; padding: .75rem; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: #1A56DB; color: #fff; font-weight: 600; cursor: pointer; margin-top: 1rem; }
        .error { color: #f87171; font-size: .85rem; }
    </style>
</head>
<body>
<div class="card">
    <h1>Verificación en dos pasos</h1>
    <p>Introduce el código de tu app autenticadora o un código de recuperación.</p>
    <form method="post" action="{{ route('two-factor.verify') }}">
        @csrf
        <input name="code" autocomplete="one-time-code" required placeholder="123456">
        @error('code') <p class="error">{{ $message }}</p> @enderror
        <button type="submit">Verificar</button>
    </form>
</div>
</body>
</html>
