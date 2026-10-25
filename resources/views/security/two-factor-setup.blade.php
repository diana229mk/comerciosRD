<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar 2FA — ComerciosRD</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #1e293b; border-radius: 16px; padding: 2rem; max-width: 420px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,.4); }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: #94a3b8; font-size: .9rem; }
        .qr { background: #fff; border-radius: 12px; padding: 1rem; display: flex; justify-content: center; margin: 1rem 0; }
        input { width: 100%; padding: .75rem; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #fff; box-sizing: border-box; }
        button, .btn { display: inline-block; width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: #1A56DB; color: #fff; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; margin-top: 1rem; }
        .error { color: #f87171; font-size: .85rem; }
        .codes { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; font-family: ui-monospace, monospace; }
        .codes span { background: #0f172a; padding: .4rem; border-radius: 6px; text-align: center; }
        .secret { font-size: .75rem; word-break: break-all; color: #64748b; }
    </style>
</head>
<body>
<div class="card">
    @if (!empty($recoveryCodes))
        <h1>2FA activado</h1>
        <p>Guarda estos códigos de recuperación en un lugar seguro. Solo se muestran una vez.</p>
        <div class="codes" style="margin:1rem 0">
            @foreach ($recoveryCodes as $code)
                <span>{{ $code }}</span>
            @endforeach
        </div>
        <a class="btn" href="{{ url('/admin') }}">Continuar al panel</a>
    @else
        <h1>Autenticación en dos pasos</h1>
        <p>Obligatorio para dueños, administradores y gerentes. Escanea el QR con Google Authenticator o Authy.</p>
        @if ($qrSvg)
            <div class="qr">{!! $qrSvg !!}</div>
        @endif
        @if ($secret)
            <p class="secret">Clave manual: {{ $secret }}</p>
        @endif
        <form method="post" action="{{ route('two-factor.enable') }}">
            @csrf
            <label for="code">Código de 6 dígitos</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="12">
            @error('code') <p class="error">{{ $message }}</p> @enderror
            <button type="submit">Activar 2FA</button>
        </form>
    @endif
</div>
</body>
</html>
