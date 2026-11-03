<?php

namespace App\Traits;

use App\Scopes\BranchScope;

/**
 * FASE S4a — Aísla las lecturas del modelo por sucursal.
 *
 * Aplica el global scope BranchScope, que solo activa el filtro cuando el
 * negocio tiene la feature `strict_branch_scope` y el usuario NO es
 * owner/admin/supervisor. Ver App\Scopes\BranchScope para reglas exactas.
 *
 * Modelos objetivo (operaciones): Sale, Order, CashSession, Expense, Purchase.
 *
 * ProductBatch e InventoryMovement mantienen su guarda en InventoryService:
 * aplicarles este scope podría ocultar lotes legítimos durante ventas.
 * StockCount y StockCountItem sí usan este scope porque cada conteo pertenece
 * a una sucursal y no debe exponer ni modificar conteos de otras sucursales.
 */
trait ScopedByBranch
{
    protected static function bootScopedByBranch(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}
