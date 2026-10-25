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
 * NO se agrega a modelos de inventario (ProductBatch, InventoryMovement,
 * StockCount, StockCountItem): esos ya tienen su propia guarda opt-in vía
 * InventoryService (feature `strict_branch_stock`) y aplicar dos scopes
 * distintos podría ocultar lotes legítimos durante ventas.
 */
trait ScopedByBranch
{
    protected static function bootScopedByBranch(): void
    {
        static::addGlobalScope(new BranchScope());
    }
}
