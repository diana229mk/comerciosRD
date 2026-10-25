<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use BelongsToBusiness;
    use HasUuids;
    use SoftDeletes;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_INCOME = 'income';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_COST = 'cost';

    protected $fillable = [
        // business_id: trait
        'code',
        'name',
        'type',
        'description',
        'is_active',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_ASSET => 'Activo',
            self::TYPE_LIABILITY => 'Pasivo',
            self::TYPE_EQUITY => 'Capital',
            self::TYPE_INCOME => 'Ingreso',
            self::TYPE_EXPENSE => 'Gasto',
            self::TYPE_COST => 'Costo de Venta',
        ];
    }

    public function getTypeLabelAttribute(): string
    {
        return self::types()[$this->type] ?? (string) $this->type;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Plan de cuentas por defecto (RD básico) para un negocio.
     *
     * @return list<array{code: string, name: string, type: string, is_system: bool, description?: string}>
     */
    public static function defaultChart(): array
    {
        return [
            // Activos
            ['code' => '1101', 'name' => 'Caja', 'type' => self::TYPE_ASSET, 'is_system' => true, 'description' => 'Efectivo en caja'],
            ['code' => '1102', 'name' => 'Banco', 'type' => self::TYPE_ASSET, 'is_system' => true, 'description' => 'Cuentas bancarias'],
            ['code' => '1201', 'name' => 'Cuentas por Cobrar', 'type' => self::TYPE_ASSET, 'is_system' => true],
            ['code' => '1301', 'name' => 'Inventario de Mercancías', 'type' => self::TYPE_ASSET, 'is_system' => true],

            // Pasivos
            ['code' => '2101', 'name' => 'Cuentas por Pagar', 'type' => self::TYPE_LIABILITY, 'is_system' => true],
            ['code' => '2201', 'name' => 'ITBIS por Pagar', 'type' => self::TYPE_LIABILITY, 'is_system' => true],

            // Capital
            ['code' => '3101', 'name' => 'Capital del Dueño', 'type' => self::TYPE_EQUITY, 'is_system' => true],
            ['code' => '3201', 'name' => 'Utilidades Retenidas', 'type' => self::TYPE_EQUITY, 'is_system' => true],

            // Ingresos
            ['code' => '4101', 'name' => 'Ventas', 'type' => self::TYPE_INCOME, 'is_system' => true],
            ['code' => '4201', 'name' => 'Otros Ingresos', 'type' => self::TYPE_INCOME, 'is_system' => false],

            // Costos
            ['code' => '5101', 'name' => 'Costo de Ventas', 'type' => self::TYPE_COST, 'is_system' => true],

            // Gastos
            ['code' => '6101', 'name' => 'Gastos Operativos', 'type' => self::TYPE_EXPENSE, 'is_system' => true],
            ['code' => '6102', 'name' => 'Gastos de Alquiler', 'type' => self::TYPE_EXPENSE, 'is_system' => false],
            ['code' => '6103', 'name' => 'Servicios (Luz, Agua, Internet)', 'type' => self::TYPE_EXPENSE, 'is_system' => false],
            ['code' => '6104', 'name' => 'Sueldos y Salarios', 'type' => self::TYPE_EXPENSE, 'is_system' => false],
            ['code' => '6199', 'name' => 'Otros Gastos', 'type' => self::TYPE_EXPENSE, 'is_system' => false],
        ];
    }
}
