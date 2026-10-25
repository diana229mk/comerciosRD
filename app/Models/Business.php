<?php

namespace App\Models;

use App\Enums\BusinessType;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Business extends Model implements HasName, HasAvatar, HasCurrentTenantLabel
{
    use HasUuids;
    use SoftDeletes;

    /**
     * Tipos de negocio (UI / Master).
     * Columna DB: `type` — accesible también como `business_type`.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'colmado' => 'Colmado / Minimarket',
        'farmacia' => 'Farmacia',
        'ferreteria' => 'Ferretería',
        'repuestos' => 'Repuestos / Autopartes',
        'cafeteria' => 'Cafetería / Restaurante',
        'tienda' => 'Tienda / Boutique',
        'distribuidora' => 'Distribuidora / Mayorista',
        'almacen' => 'Almacén',
        'general' => 'General / Otro',
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'owner_id',
        'logo',
        'phone',
        'email',
        'address',
        'city',
        'province',
        'rnc',
        'is_active',
        'subscription_plan',
        'extra_seats',
        'promo_percent',
        'promo_until',
        'promo_note',
        'enabled_modules',
        'onboarding_completed',
        'settings',
        'features',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => BusinessType::class,
            'is_active' => 'boolean',
            'subscription_plan' => \App\Enums\SubscriptionPlan::class,
            'extra_seats' => 'integer',
            'promo_percent' => 'integer',
            'promo_until' => 'date',
            'enabled_modules' => 'array',
            'onboarding_completed' => 'boolean',
            'settings' => 'array',
            'features' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    /**
     * Alias de lectura: business_type → código string del tipo.
     * Uso: filament()->getTenant()?->business_type
     */
    public function getBusinessTypeAttribute(): string
    {
        return $this->typeKey();
    }

    /**
     * Alias de escritura: permite setear business_type en formularios.
     */
    public function setBusinessTypeAttribute(mixed $value): void
    {
        if ($value instanceof BusinessType) {
            $this->attributes['type'] = $value->value;

            return;
        }

        $normalized = BusinessType::normalizeKey($value);

        $this->attributes['type'] = $normalized ?? BusinessType::General->value;
    }

    /**
     * Etiqueta legible del tipo de negocio.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->business_type] ?? 'General';
    }

    /**
     * Código del tipo (string) para match/UI.
     */
    public function typeKey(): string
    {
        if ($this->type instanceof BusinessType) {
            return $this->type->value;
        }

        $raw = $this->attributes['type'] ?? $this->getRawOriginal('type') ?? null;
        $normalized = BusinessType::normalizeKey($raw);

        return $normalized ?? BusinessType::General->value;
    }

    /**
     * Lista de todos los módulos disponibles en el sistema.
     *
     * @return array<string, string>
     */
    public static function availableModules(): array
    {
        return [
            // Inventario
            'products' => 'Productos e Inventario',
            'categories' => 'Categorías de Productos',
            'inventory_movements' => 'Movimientos de Inventario',

            // Pharmacy / advanced
            'prescriptions' => 'Prescripciones Médicas',
            'stock_counts' => 'Conteos de Inventario',

            // Operaciones
            'orders' => 'Órdenes',
            'sales' => 'Ventas (Punto de Venta)',
            'cash_register' => 'Caja / Arqueo',

            // Clientes y Cobros
            'customers' => 'Clientes',
            'receivables' => 'Cuentas por Cobrar',

            // Gastos y Pagos
            'expenses' => 'Gastos',
            'payables' => 'Cuentas por Pagar',

            // Compras
            'suppliers' => 'Proveedores',
            'purchases' => 'Compras',

            // Contabilidad
            'accounting' => 'Contabilidad Básica',

            // Facturación Electrónica (estructura base; emisión DGII en fase posterior)
            'electronic_invoicing' => 'Facturación Electrónica (e-CF)',

            // Configuración
            'employees' => 'Usuarios Secundarios / Empleados',

            // Optional / advanced modules
            'variants' => 'Variantes de Producto',
            'units' => 'Unidades de Medida',
            'locations' => 'Ubicaciones / Almacenes',
            'kits' => 'Kits / Productos compuestos',
            'price_lists' => 'Listas de Precios',
            'quotes' => 'Cotizaciones',
            'serials' => 'Control por Serial',
            'transfers' => 'Transferencias entre ubicaciones',
            'special_orders' => 'Órdenes especiales',
            'seasons' => 'Temporadas',
            'loyalty' => 'Fidelidad / Puntos',
            'gift_cards' => 'Tarjetas de Regalo',

            // Reportes
            'reports' => 'Reportes Avanzados',
        ];
    }

    /**
     * Módulos con UI real en el panel Admin (excluye “futuros”).
     *
     * @return list<string>
     */
    public static function implementedModuleKeys(): array
    {
        return [
            'products',
            'categories',
            'inventory_movements',
            'orders',
            'sales',
            'cash_register',
            'customers',
            'receivables',
            'expenses',
            'payables',
            'suppliers',
            'purchases',
            'employees',
            'reports',
            'accounting',
            'electronic_invoicing',
        ];
    }

    /**
     * Módulos prioritarios según tipo de negocio (creación / sugerencia Master).
     *
     * @return list<string>
     */
    public static function defaultModulesFor(BusinessType|string|null $type): array
    {
        $key = $type instanceof BusinessType
            ? $type->value
            : (is_string($type) && $type !== '' ? (BusinessType::normalizeKey($type) ?? BusinessType::General->value) : BusinessType::General->value);

        return match ($key) {
            'colmado' => [
                'products', 'categories', 'sales', 'orders', 'customers',
                'receivables', 'expenses', 'cash_register', 'reports', 'employees',
            ],
            'farmacia' => [
                'products', 'categories', 'inventory_movements', 'sales', 'customers',
                'receivables', 'expenses', 'cash_register', 'reports', 'employees',
                'prescriptions', 'stock_counts', 'orders', 'quotes',
            ],
            'ferreteria' => [
                'products', 'categories', 'inventory_movements', 'sales', 'orders',
                'customers', 'suppliers', 'purchases', 'payables', 'expenses',
                'cash_register', 'reports', 'employees',
                'variants', 'units', 'locations', 'kits', 'price_lists', 'quotes', 'serials',
                'transfers', 'special_orders', 'stock_counts',
            ],
            'repuestos' => [
                'products', 'categories', 'inventory_movements', 'sales', 'orders',
                'customers', 'suppliers', 'purchases', 'receivables', 'payables',
                'expenses', 'cash_register', 'reports', 'employees',
                'quotes',
            ],
            'cafeteria' => [
                'products', 'categories', 'inventory_movements', 'orders', 'sales', 'customers',
                'expenses', 'cash_register', 'reports', 'employees',
                'kits',
            ],
            'tienda' => [
                'products', 'categories', 'inventory_movements', 'sales', 'customers',
                'suppliers', 'purchases', 'receivables', 'payables',
                'expenses', 'cash_register', 'reports', 'employees',
                'variants', 'units', 'locations', 'kits', 'price_lists', 'quotes',
                'transfers', 'stock_counts',
                'seasons', 'loyalty', 'gift_cards',
            ],
            'distribuidora', 'almacen' => [
                'products', 'categories', 'inventory_movements', 'sales', 'orders',
                'customers', 'suppliers', 'purchases', 'receivables', 'payables',
                'expenses', 'cash_register', 'reports', 'employees',
            ],
            default => [
                'products', 'categories', 'sales', 'customers', 'expenses',
                'cash_register', 'reports', 'employees',
            ],
        };
    }

    /**
     * Aplica módulos por defecto del tipo si aún no hay lista.
     * No sobrescribe una selección manual (plan / Master).
     */
    public function ensureDefaultModules(): void
    {
        if (! empty($this->enabled_modules) && is_array($this->enabled_modules)) {
            return;
        }

        $this->forceFill([
            'enabled_modules' => self::defaultModulesFor($this->business_type),
        ])->save();
    }

    /**
     * Sincroniza módulos al tipo (Master: “aplicar defaults del plan”).
     * Solo se usa cuando el operador lo pide explícitamente.
     */
    public function applyDefaultModulesForType(bool $force = false): void
    {
        if (! $force && ! empty($this->enabled_modules) && is_array($this->enabled_modules)) {
            return;
        }

        $this->forceFill([
            'enabled_modules' => self::defaultModulesFor($this->business_type),
        ])->save();
    }

    /**
     * ¿El negocio tiene este módulo activo?
     * Sin configuración (null/vacío) → todos los módulos (compatibilidad).
     */
    public function hasModule(string $module): bool
    {
        $enabled = $this->enabled_modules;
        if (empty($enabled) || ! is_array($enabled)) {
            $enabled = self::defaultModulesFor($this->type);
        }

        if (in_array($module, $enabled, true)) {
            return true;
        }

        // Alias legacy: cash → cash_register
        if ($module === 'cash_register' && in_array('cash', $enabled, true)) {
            return true;
        }
        if ($module === 'cash' && in_array('cash_register', $enabled, true)) {
            return true;
        }

        // Cafetería existente: cocina necesita recetas + movimientos aunque el plan viejo no los listara.
        if ($this->isCafeteria() && in_array($module, ['kits', 'inventory_movements'], true)) {
            return true;
        }

        if ($this->isPharmacy() && in_array($module, ['orders', 'quotes'], true)) {
            return true;
        }

        return false;
    }

    /**
     * ¿Tiene al menos uno de los módulos?
     */
    public function hasAnyModule(string ...$modules): bool
    {
        if ($modules === []) {
            return true;
        }

        foreach ($modules as $module) {
            if ($this->hasModule($module)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tenant Filament actual (o null).
     */
    public static function currentTenant(): ?self
    {
        try {
            $tenant = filament()->getTenant();
        } catch (\Throwable) {
            return null;
        }

        return $tenant instanceof self ? $tenant : null;
    }

    // ==================== RELACIONES ====================

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_business')
            ->withPivot(['role', 'is_active', 'joined_at', 'branch_id'])
            ->withTimestamps();
    }

    public function subscriptionInvoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productCategories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Sucursales del negocio (FASE S1).
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class);
    }

    /**
     * Sucursal principal del negocio (is_main=true). Puede no existir.
     */
    public function mainBranch(): ?Branch
    {
        return $this->branches()
            ->where('is_main', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Crea "Principal" si el negocio aún no tiene sucursal principal activa.
     * Usado al dar de alta el comercio. No toca sucursales ya existentes.
     */
    public function ensureMainBranch(): Branch
    {
        $existing = $this->mainBranch();
        if ($existing instanceof Branch) {
            return $existing;
        }

        $previousId = app()->bound('currentBusinessId') ? app('currentBusinessId') : null;
        $previousBiz = app()->bound('currentBusiness') ? app('currentBusiness') : null;

        app()->instance('currentBusinessId', (string) $this->getKey());
        app()->instance('currentBusiness', $this);

        try {
            $codeTaken = $this->branches()->where('code', 'PRIN')->exists();

            $branch = new Branch;
            $branch->forceFill([
                'name' => 'Principal',
                'code' => $codeTaken ? null : 'PRIN',
                'is_main' => true,
                'is_active' => true,
                'address' => $this->address,
                'phone' => $this->phone,
            ]);
            $branch->setAttribute('business_id', (string) $this->getKey());
            $branch->save();

            return $branch;
        } finally {
            if ($previousId !== null) {
                app()->instance('currentBusinessId', $previousId);
            }
            if ($previousBiz instanceof self) {
                app()->instance('currentBusiness', $previousBiz);
            }
        }
    }

    // ==================== FILAMENT INTERFACES ====================

    /**
     * Contrato Filament HasName (getFilamentName, no getName).
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    public function getCurrentTenantLabel(): string
    {
        return $this->name;
    }

    // ==================== HELPERS ====================

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function taxRate(): float
    {
        return (float) $this->setting('tax.rate', 18);
    }

    /**
     * false = el POS suma ITBIS al precio (histórico, no rompe comercios actuales).
     * true  = el precio de góndola ya trae ITBIS; el cliente paga ese monto.
     */
    public function pricesIncludeTax(): bool
    {
        return (bool) $this->setting('tax.prices_include_tax', false);
    }

    /**
     * Promoción de cuota asignada por Master a este local (no es global).
     */
    public function hasActivePromo(): bool
    {
        $percent = max(0, min(100, (int) ($this->promo_percent ?? 0)));
        if ($percent <= 0) {
            return false;
        }

        $until = $this->promo_until;
        if ($until === null) {
            return true;
        }

        return $until->copy()->startOfDay()->greaterThanOrEqualTo(now()->startOfDay());
    }

    public function promoPercent(): int
    {
        return $this->hasActivePromo()
            ? max(0, min(100, (int) ($this->promo_percent ?? 0)))
            : 0;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) data_get($this->features, $feature, false);
    }

    public function monthlySalesGoal(): float
    {
        return (float) $this->setting('dashboard.monthly_sales_goal', 0);
    }

    /**
     * @return array<string, bool>
     */
    public function dashboardWidgets(): array
    {
        $defaults = \App\Support\DashboardWidgetRegistry::defaultsFor($this->type);
        $saved = $this->setting('dashboard.widgets', []);

        if (! is_array($saved)) {
            $saved = [];
        }

        return array_merge($defaults, $saved);
    }

    public function isDashboardWidgetEnabled(string $key): bool
    {
        return (bool) ($this->dashboardWidgets()[$key] ?? false);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    // ==================== FARMACIA ====================

    /**
     * ¿El negocio es una farmacia? (aplica reglas de lotes/FEFO/vencimientos)
     */
    public function isPharmacy(): bool
    {
        return $this->typeKey() === BusinessType::Farmacia->value;
    }

    /**
     * ¿El negocio es una ferretería? (habilita variantes, kits, unidades múltiples, series, cotizaciones)
     */
    public function isHardwareStore(): bool
    {
        return $this->typeKey() === BusinessType::Ferreteria->value;
    }

    public function isAutoPartsStore(): bool
    {
        return $this->typeKey() === BusinessType::Repuestos->value;
    }

    public function isRetailStore(): bool
    {
        return $this->typeKey() === BusinessType::Tienda->value;
    }

    public function isCafeteria(): bool
    {
        return $this->typeKey() === BusinessType::Cafeteria->value;
    }

    /**
     * Platos con receta (kits de ingredientes). No activa series/IMEI de ferretería.
     */
    public function usesKitchenRecipes(): bool
    {
        return $this->isCafeteria();
    }

    /**
     * Comercios que comparten features de inventario avanzado
     * (variantes, unidades múltiples, kits, ubicaciones, transferencias, IA de inventario).
     */
    public function usesAdvancedInventory(): bool
    {
        return $this->isHardwareStore() || $this->isAutoPartsStore() || $this->isRetailStore();
    }

    /**
     * Días de proximidad al vencimiento para alertar (default 30).
     */
    public function pharmacyNearExpiryDays(): int
    {
        $value = (int) $this->setting('pharmacy.near_expiry_days', 30);

        return max(0, min(365, $value));
    }

    /**
     * Modo de manejo de recetas: 'basic' o 'strict' (default 'strict').
     * En 'basic' se permite registrar la receta rápido (solo paciente + médico)
     * y despachar productos con requires_prescription sin renglón de receta,
     * siempre que se aporte al menos una referencia mínima. Medicamentos
     * controlados (Anexo I/II/psicotrópico) siempre exigen strict.
     */
    public function pharmacyPrescriptionMode(): string
    {
        $mode = (string) $this->setting('pharmacy.prescription_mode', 'strict');

        return in_array($mode, ['basic', 'strict'], true) ? $mode : 'strict';
    }

    public function pharmacyPrescriptionIsBasic(): bool
    {
        return $this->isPharmacy() && $this->pharmacyPrescriptionMode() === 'basic';
    }

    /**
     * ¿Se bloquea la venta de productos vencidos?
     * En farmacia SIEMPRE es true por seguridad (no configurable).
     * En otros negocios: false.
     */
    public function blocksExpiredSales(): bool
    {
        return $this->isPharmacy();
    }

    /**
     * ¿Se bloquea también la venta de lotes próximos a vencer?
     * Configurable: default false (solo alerta), pero se puede activar.
     */
    public function blocksNearExpirySales(): bool
    {
        if (! $this->isPharmacy()) {
            return false;
        }

        return (bool) $this->setting('pharmacy.block_near_expiry', false);
    }

    /**
     * Días bajo los cuales una venta de lote se bloquea (si blocksNearExpirySales).
     * Devuelve 0 cuando no se bloquea (para uso simple en queries/services).
     */
    public function nearExpiryBlockDays(): int
    {
        return $this->blocksNearExpirySales() ? $this->pharmacyNearExpiryDays() : 0;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Business $business): void {
            $source = trim((string) ($business->slug ?? ''));
            if ($source === '') {
                $source = (string) $business->name;
            }
            $business->slug = static::uniqueSlug(
                $source,
                $business->exists ? (string) $business->getKey() : null
            );
        });
    }

    /**
     * Slug URL-safe y libre. Si "aa" ya existe, devuelve "aa-1".
     */
    public static function uniqueSlug(string $desired, ?string $ignoreId = null): string
    {
        $base = Str::slug($desired) ?: 'comercio';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
