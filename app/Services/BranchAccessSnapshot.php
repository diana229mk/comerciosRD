<?php

namespace App\Services;

/**
 * FASE H1 — Snapshot inmutable del contexto de acceso por sucursal para un
 * (user × business) específico.
 *
 * Producido por BranchAccessContext. Consumido por BranchScope y TenantPolicy.
 */
final class BranchAccessSnapshot
{
    public function __construct(
        public readonly string $businessId,
        public readonly bool $featureEnabled,
        public readonly bool $isAdmin,
        public readonly ?string $role,
        public readonly ?string $branchId,
        public readonly ?string $viewBranchId = null,
        public readonly bool $canSwitchBranches = false,
    ) {}

    /**
     * Dueño/admin de casa matriz puede ver todo o un local.
     * Encargado/cajero de un local siempre ve solo ese local.
     */
    public function shouldRestrictReads(): bool
    {
        return $this->effectiveBranchId() !== null;
    }

    public function effectiveBranchId(): ?string
    {
        if ($this->canSwitchBranches) {
            return $this->viewBranchId;
        }

        return $this->branchId ?: $this->viewBranchId;
    }
}
