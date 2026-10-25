<?php

namespace App\Http\Responses;

use App\Services\AuthContextResolver;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Tras el login, ir al puesto (preventa / caja / inventario), no al
 * "intended" que suele ser el dashboard 403 que el usuario acaba de chocar.
 */
class TenantLoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $dest = session(AuthContextResolver::SESSION_PANEL_DESTINATION);

        if (! is_string($dest) || $dest === '') {
            $url = Filament::getUrl();
            $dest = is_string($url) && $url !== '' ? $url : url('/admin/login');
        }

        // redirect() en Livewire guarda el efecto "redirect" del componente.
        // new RedirectResponse() no: el login autentica y se queda en /admin/login.
        return redirect()->to($dest);
    }
}
