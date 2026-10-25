<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sucursal (branch) de un negocio.
 *
 * FASE S1 — Sólo estructura de datos y CRUD. No modifica operaciones
 * (ventas, inventario, caja, reportes). Al asignar sucursal a un usuario
 * secundario, se guarda en el pivot user_business y en la sesión, pero
 * ningún módulo operativo la usa aún.
 */
class Branch extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'is_main',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Usuarios asignados a esta sucursal a través del pivot user_business.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_business')
            ->wherePivot('branch_id', $this->getKey())
            ->withPivot(['role', 'is_active', 'joined_at', 'branch_id'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('is_main', true);
    }
}
