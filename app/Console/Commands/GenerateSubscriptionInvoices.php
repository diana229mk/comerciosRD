<?php

namespace App\Console\Commands;

use App\Services\SubscriptionBillingService;
use Illuminate\Console\Command;

class GenerateSubscriptionInvoices extends Command
{
    protected $signature = 'subscriptions:generate-invoices';

    protected $description = 'Genera o actualiza las cuotas del mes de cada negocio activo (ComerciosRD).';

    public function handle(SubscriptionBillingService $billing): int
    {
        $n = $billing->generateOpenInvoices();
        $this->info("Negocios revisados: {$n}.");

        return self::SUCCESS;
    }
}
