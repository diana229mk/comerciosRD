<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasTenants, HasDefaultTenant
{
    use HasFactory;
    use HasUuids;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'current_business_id',
        'is_master_admin',
        'is_active',
        'password_changed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_master_admin' => 'boolean',
            'is_active' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // two_factor_secret y recovery se cifran vía Crypt en TwoFactorService
        ];
    }

    /**
     * ¿Tiene 2FA TOTP confirmado? (alias de TwoFactorService::isEnabled)
     */
    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && filled($this->two_factor_confirmed_at);
    }

    // ==================== MULTI-TENANCY ====================

    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'user_business')
            ->withPivot(['role', 'is_active', 'joined_at', 'branch_id', 'login_name'])
            ->withTimestamps();
    }

    public function activeBusinesses(): BelongsToMany
    {
        return $this->businesses()->wherePivot('is_active', true);
    }

    public function getTenants(Panel $panel): Collection
    {
        // Un usuario real tiene 1–4 negocios. El tope evita hidratar de más
        // si algún master cae en el panel admin. El listado de 100k comercios
        // vive en el panel master, con búsqueda, no aquí.
        $limit = max(1, (int) config('scale.user_tenant_limit', 25));

        return $this->activeBusinesses()->limit($limit)->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Business
            && $tenant->is_active
            && $this->businesses()
                ->whereKey($tenant->getKey())
                ->wherePivot('is_active', true)
                ->exists();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        // Primero intenta el current_business_id
        if ($this->current_business_id) {
            $business = $this->activeBusinesses()
                ->where('businesses.id', $this->current_business_id)
                ->first();

            if ($business) {
                return $business;
            }
        }

        // Si no, el primer negocio activo
        return $this->activeBusinesses()->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Usuarios suspendidos no pueden acceder a ningún panel
        if (($this->is_active ?? true) === false) {
            return false;
        }

        // Panel Master: solo master admins.
        // El 2FA se fuerza en EnsureTwoFactorVerified (si canAccessPanel exigiera
        // 2FA aquí, un master nuevo nunca podría autenticarse para configurarlo).
        if ($panel->getId() === 'master') {
            return (bool) ($this->is_master_admin ?? false);
        }

        // Panel Admin normal
        return $this->activeBusinesses()->exists();
    }

    // Helper útil
    public function belongsToBusiness(string $businessId): bool
    {
        return $this->businesses()
            ->where('businesses.id', $businessId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    /**
     * Rol del usuario dentro de un negocio (pivot user_business.role).
     */
    public function roleInBusiness(string $businessId): ?string
    {
        return $this->businesses()
            ->where('businesses.id', $businessId)
            ->first()
            ?->pivot
            ?->role;
    }

    /**
     * ID de la sucursal asignada al usuario en un negocio (pivot).
     * Puede ser null si el negocio aún no usa sucursales.
     */
    public function branchInBusiness(string $businessId): ?string
    {
        $branchId = $this->businesses()
            ->where('businesses.id', $businessId)
            ->first()
            ?->pivot
            ?->branch_id;

        return $branchId !== null && $branchId !== '' ? (string) $branchId : null;
    }

    /**
     * Rol del usuario en el negocio (tenant) actual de Filament.
     */
    public function getRoleInCurrentBusiness(): ?string
    {
        try {
            $tenant = filament()->getTenant();
        } catch (\Throwable) {
            $tenant = null;
        }

        $businessId = $tenant?->getKey()
            ?? session(\App\Services\AuthContextResolver::SESSION_BUSINESS_ID)
            ?? (app()->bound('currentBusinessId') ? app('currentBusinessId') : null)
            ?? $this->current_business_id;

        if ($businessId) {
            $role = $this->roleInBusiness((string) $businessId);
            if ($role) {
                return $role;
            }
        }

        $sessionRole = session(\App\Services\AuthContextResolver::SESSION_ROLE);

        return is_string($sessionRole) && $sessionRole !== '' ? $sessionRole : null;
    }

    /**
     * ¿Tiene uno de los roles indicados en el negocio actual?
     *
     * @param  array<int, string>|string  $roles
     */
    public function hasRoleInBusiness(array|string $roles): bool
    {
        $currentRole = $this->getRoleInCurrentBusiness();

        if (! $currentRole) {
            return false;
        }

        // Compatibilidad con rol legacy "manager"
        if ($currentRole === 'manager') {
            $currentRole = 'supervisor';
        }

        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($currentRole, $roles, true);
    }

    /**
     * Cajero “puro”: solo rol cashier (sin owner/admin/supervisor).
     * Menú mínimo: POS, Ventas, Caja, Clientes.
     */
    public function isStrictCashier(): bool
    {
        if (! $this->hasRoleInBusiness(['cashier'])) {
            return false;
        }

        return ! $this->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'manager']);
    }

    /**
     * ¿Puede realizar una acción específica? (config/permissions.php)
     * Ej: view_sales, create_orders, edit_products
     */
    public function canPerform(string $permission): bool
    {
        $role = $this->getRoleInCurrentBusiness();

        if (! $role) {
            return false;
        }

        // Compatibilidad con rol legacy "manager"
        if ($role === 'manager') {
            $role = 'supervisor';
        }

        /** @var list<string> $permissions */
        $permissions = config("permissions.roles.{$role}", []);

        if (! is_array($permissions)) {
            return false;
        }

        // El dueño (*) tiene acceso a todo
        if (in_array('*', $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /**
     * ¿Puede realizar al menos una de las acciones?
     *
     * @param  list<string>  $permissions
     */
    public function canPerformAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->canPerform($permission)) {
                return true;
            }
        }

        return false;
    }
}
