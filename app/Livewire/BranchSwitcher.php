<?php

namespace App\Livewire;

use App\Models\Branch;
use App\Models\Business;
use App\Services\AuthContextResolver;
use App\Support\BusinessRole;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * FASE S4b — Selector de sucursal en el topbar del panel admin.
 *
 * Se muestra sólo si:
 *   - Hay tenant activo (negocio) con al menos 2 sucursales activas.
 *   - El usuario tiene acceso a más de una (owner/admin/supervisor: todas;
 *     cajero/vendedor: sólo la asignada → se muestra como badge de solo lectura).
 *
 * Al cambiar el select se persiste en session('current_branch_id') y se recarga
 * la página para que los queries respeten el nuevo contexto (S4a + FEFO S3a+).
 *
 * NO cambia el pivot user_business.branch_id (eso es asignación permanente).
 * Sólo cambia el contexto de sesión "estoy operando desde esta sucursal".
 */
class BranchSwitcher extends Component
{
    public ?string $branchId = null;

    public function mount(): void
    {
        $this->branchId = session(AuthContextResolver::SESSION_BRANCH_ID);
    }

    public function updatedBranchId(?string $value): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Business) {
            return;
        }

        if ($value === '' || $value === null) {
            session()->forget(AuthContextResolver::SESSION_BRANCH_ID);
            $this->redirect($this->safeRedirectUrl($tenant), navigate: false);

            return;
        }

        // Validar: la sucursal debe pertenecer al negocio actual y estar activa.
        $branch = Branch::query()
            ->where('id', $value)
            ->where('business_id', $tenant->getKey())
            ->where('is_active', true)
            ->first();

        if (! $branch) {
            $this->branchId = session(AuthContextResolver::SESSION_BRANCH_ID);

            return;
        }

        // Encargado/cajero de un local no puede saltar a otro.
        $user = Auth::user();
        if ($user && ! $this->canChooseDashboardScope($user, $tenant)) {
            $pivotBranchId = $user->businesses()
                ->where('businesses.id', $tenant->getKey())
                ->first()
                ?->pivot?->branch_id;

            if ($pivotBranchId && (string) $pivotBranchId !== (string) $value) {
                $this->branchId = (string) $pivotBranchId;

                return;
            }
        }

        session()->put(AuthContextResolver::SESSION_BRANCH_ID, (string) $branch->getKey());

        // FASE H1: invalidar snapshot cacheado para que el nuevo branch sea
        // efectivo en el próximo render (aunque en la práctica el switcher
        // rechaza cambios para no-admins, mantenemos la invalidación por si
        // se agrega feature de "mover cajero de sucursal" en el futuro).
        app(\App\Services\BranchAccessContext::class)->forget((string) $tenant->getKey());

        $this->redirect($this->safeRedirectUrl($tenant), navigate: false);
    }

    /**
     * FASE H5 · Fallback robusto de redirect tras cambio de sucursal.
     *
     * Prioridad:
     *  1) Referer del request (si está y es del mismo host).
     *  2) URL raíz del panel admin del tenant (dashboard).
     *  3) '/' como último recurso.
     *
     * Antes usábamos Referer ?? '/', que dejaba al user en '/' cuando el
     * navegador no envía Referer (política estricta, WebView de apps móviles).
     */
    private function safeRedirectUrl(Business $tenant): string
    {
        $referer = request()->header('Referer');
        if (is_string($referer) && $referer !== '') {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host === request()->getHost()) {
                return $referer;
            }
        }

        try {
            return Filament::getUrl($tenant);
        } catch (\Throwable) {
            return '/';
        }
    }

    public function render(): View
    {
        $tenant = Filament::getTenant();
        $branches = collect();
        $canSwitch = false;

        if ($tenant instanceof Business) {
            $branches = Branch::query()
                ->where('business_id', $tenant->getKey())
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']);

            $user = Auth::user();
            if ($user && $branches->count() >= 2) {
                $canSwitch = $this->canChooseDashboardScope($user, $tenant);
            }
        }

        $user = Auth::user();
        $role = null;
        if ($user && $tenant instanceof Business) {
            $role = $user->roleInBusiness((string) $tenant->getKey());
        }

        return view('livewire.branch-switcher', [
            'branches' => $branches,
            'canSwitch' => $canSwitch,
            'currentBranch' => $branches->firstWhere('id', $this->branchId),
            'businessName' => $tenant instanceof Business ? (string) $tenant->name : null,
            'userName' => $user?->name,
            'roleLabel' => BusinessRole::shortLabel($role),
        ]);
    }

    private function canChooseDashboardScope($user, Business $business): bool
    {
        if ((string) $business->owner_id === (string) $user->getKey()) {
            return true;
        }

        $pivot = $user->businesses()
            ->where('businesses.id', $business->getKey())
            ->first()
            ?->pivot;

        if ($pivot && filled($pivot->branch_id)) {
            return false;
        }

        $role = $pivot?->role;
        if ($role === 'manager') {
            $role = 'supervisor';
        }

        return in_array($role, ['owner', 'admin', 'supervisor'], true);
    }
}
