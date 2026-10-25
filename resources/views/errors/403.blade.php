@php
    use App\Services\AuthContextResolver;

    $user = auth()->user();
    $destination = session(AuthContextResolver::SESSION_PANEL_DESTINATION);
    try {
        $tenant = filament()->getTenant();
        if ($user instanceof \App\Models\User && $tenant instanceof \App\Models\Business) {
            $destination = app(AuthContextResolver::class)->panelDestination($user, $tenant);
        }
    } catch (\Throwable) {
    }

    $destination = is_string($destination) && $destination !== ''
        ? $destination
        : url('/admin/login');
    $role = session(AuthContextResolver::SESSION_ROLE);
    $destPath = parse_url($destination, PHP_URL_PATH) ?: $destination;
    $samePlace = trim(request()->getPathInfo(), '/') === trim((string) $destPath, '/');
    $autoGo = in_array($role, ['seller', 'cashier', 'inventory'], true) && ! $samePlace;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sin acceso · ComerciosRD</title>
    <style>
        *,*::before,*::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #fef3c7 0%, #fef9c3 100%);
            color: #1f2937;
            padding: 1.5rem;
        }
        .card {
            background: #ffffff;
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 28rem;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            text-align: center;
        }
        .icon {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1rem;
            background: #fef3c7;
            border-radius: 999px;
            display: grid;
            place-items: center;
            font-size: 2rem;
        }
        h1 { font-size: 1.5rem; margin: 0 0 .5rem; color: #111827; }
        p { color: #4b5563; margin: 0 0 1.5rem; line-height: 1.5; }
        .btn {
            display: inline-block;
            background: #f59e0b;
            color: #ffffff;
            padding: .75rem 1.5rem;
            border-radius: .5rem;
            text-decoration: none;
            font-weight: 600;
            transition: background .15s ease;
        }
        .btn:hover { background: #d97706; }
        .link {
            display: block;
            margin-top: 1rem;
            color: #6b7280;
            font-size: .875rem;
            text-decoration: none;
        }
        .link:hover { color: #374151; }
        @media (prefers-color-scheme: dark) {
            body { background: linear-gradient(135deg, #1f2937 0%, #111827 100%); color: #f3f4f6; }
            .card { background: #1f2937; box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
            .icon { background: #451a03; }
            h1 { color: #f9fafb; }
            p { color: #d1d5db; }
            .link { color: #9ca3af; }
            .link:hover { color: #f3f4f6; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🔒</div>
        <h1>Tu puesto no puede entrar aquí</h1>
        <p>
            @if ($role === 'cashier')
                Como cajero, tu pantalla es la caja registradora.
            @elseif ($role === 'seller')
                Como preventa, tu pantalla es Preventa (cotizaciones u órdenes). El dashboard es del dueño y el administrador.
            @elseif ($role === 'inventory')
                Como inventario, tu pantalla es el catálogo de productos.
            @elseif ($role === 'viewer')
                Tu cuenta es solo de lectura y esta sección requiere permisos adicionales.
            @else
                Esta sección requiere un rol distinto al tuyo. Habla con el dueño del negocio si necesitas acceso.
            @endif
        </p>
        <a href="{{ $destination }}" class="btn" id="role-home">Ir a mi pantalla</a>
        @if ($autoGo)
            <script>window.location.replace(@json($destination));</script>
        @endif
        <a href="{{ url('/admin/logout') }}"
           onclick="event.preventDefault(); document.getElementById('logout-form').submit();"
           class="link">Cerrar sesión</a>
        <form id="logout-form" action="{{ url('/admin/logout') }}" method="POST" style="display:none">
            @csrf
        </form>
    </div>
</body>
</html>
