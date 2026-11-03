<?php

namespace App\Console\Commands;

use App\Services\AccountingOutboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RetryAccountingOutbox extends Command
{
    protected $signature = 'accounting:outbox-retry {event} {--business= : UUID de la empresa propietaria}';

    protected $description = 'Reabre un evento contable fallido concreto, sin alterar ventas ni asientos.';

    public function handle(AccountingOutboxService $outbox): int
    {
        $id = (string) $this->argument('event');
        $businessId = (string) $this->option('business');
        if (! Str::isUuid($id) || ! Str::isUuid($businessId)
            || ! $outbox->retryFailed($id, $businessId)) {
            $this->error('Se requiere un evento fallido y su empresa correctos.');

            return self::FAILURE;
        }
        $this->info('Evento reabierto; será recuperado por el publicador.');

        return self::SUCCESS;
    }
}
