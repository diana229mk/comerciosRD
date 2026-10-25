<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * FASE 5: refresca cada request los campos volátiles del usuario autenticado
 * (is_active) desde la base, para reaccionar a cambios hechos por el dueño
 * en OTRA ventana SIN forzar logout global al momento del edit.
 *
 * - Si is_active pasó a false → logout + mensaje claro.
 * - No borra sesiones de terceros.
 * - No hace cache flush ni session flush.
 *
 * P2.1: cachea el resultado por 5s por usuario para evitar 1 SELECT por
 * request con carga alta. TTL corto = la ventana de reactividad al
 * desactivar sigue siendo aceptable (~5s).
 */
class RefreshAuthenticatedContext
{
    protected const CACHE_TTL = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        /** @var User $user */
        $user = Auth::user();

        $key = 'user.volatile:'.$user->getKey();
        /** @var array{is_active: bool, password_changed_at: ?string}|null $fresh */
        $fresh = Cache::remember($key, self::CACHE_TTL, function () use ($user): ?array {
            $row = User::query()
                ->select(['id', 'is_active', 'password_changed_at'])
                ->find($user->getKey());

            if ($row === null) {
                return null;
            }

            return [
                'is_active' => (bool) $row->is_active,
                'password_changed_at' => optional($row->password_changed_at)->toIso8601String(),
            ];
        });

        if ($fresh === null) {
            Cache::forget($key);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return self::guestLogin('Tu usuario ya no existe. Contacta al dueño.');
        }

        if ($fresh['is_active'] === false) {
            Cache::forget($key);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return self::guestLogin('Tu usuario fue desactivado. Contacta al dueño del negocio.');
        }

        $user->forceFill([
            'is_active' => $fresh['is_active'],
            'password_changed_at' => $fresh['password_changed_at'],
        ])->syncOriginalAttributes(['is_active', 'password_changed_at']);

        return $next($request);
    }

    /**
     * Invalida el caché para un usuario específico. Llamar tras editar
     * al usuario para que el TTL de 5s no retrase el efecto.
     */
    public static function invalidateFor(string $userId): void
    {
        Cache::forget('user.volatile:'.$userId);
    }

    protected static function guestLogin(string $message): RedirectResponse
    {
        $request = request();
        if ($request->hasSession()) {
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->flash('status', $message);
        }

        return new RedirectResponse(url('/admin/login'));
    }
}

