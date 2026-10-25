<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Inicializa el plan de cuentas por defecto de un negocio.
 * Delega en AccountingService (fuente canónica del plan del sistema).
 */
class ChartOfAccountsService
{
    /**
     * Crea las cuentas del sistema (idempotente).
     *
     * @return int cantidad de cuentas tras el seed
     */
    public function seedDefaults(Business|string $business): int
    {
        $model = $business instanceof Business
            ? $business
            : Business::query()->findOrFail($business);

        $before = Account::withoutBusinessScope()
            ->where('business_id', $model->getKey())
            ->count();

        AccountingService::createDefaultAccounts($model);

        $after = Account::withoutBusinessScope()
            ->where('business_id', $model->getKey())
            ->count();

        return max(0, $after - $before);
    }
}
