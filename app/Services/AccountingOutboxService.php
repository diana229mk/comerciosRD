<?php

namespace App\Services;

use App\Jobs\PostSaleAccountingJob;
use App\Models\AccountingOutbox;
use App\Models\Sale;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

class AccountingOutboxService
{
    public function register(Sale $sale, bool $resumeSkipped = false): AccountingOutbox
    {
        if ($sale->getConnection()->getDriverName() !== 'mysql'
            || $sale->getConnection()->getName() !== DB::connection()->getName()) {
            throw new \RuntimeException('Venta y outbox requieren la misma conexión MySQL.');
        }

        return TenantContext::run((string) $sale->business_id, function () use ($sale, $resumeSkipped): AccountingOutbox {
            if (! $sale->exists || ! Sale::withoutBusinessScope()
                ->where('business_id', $sale->business_id)->whereKey($sale->getKey())->exists()) {
                throw new \RuntimeException('El evento requiere una venta persistida de la misma empresa.');
            }

            $event = AccountingOutbox::withoutBusinessScope()->firstOrCreate([
                'business_id' => (string) $sale->business_id,
                'sale_id' => (string) $sale->getKey(),
            ], [
                'status' => 'pending',
                'available_at' => now(),
            ]);
            if ($resumeSkipped) {
                AccountingOutbox::withoutBusinessScope()
                    ->where('business_id', $sale->business_id)->whereKey($event->id)
                    ->where('status', 'skipped')->update([
                        'status' => 'pending', 'available_at' => now(), 'lease_token' => null,
                        'publish_attempts' => 0, 'processing_attempts' => 0,
                    ]);
                $event->refresh();
            }

            return $event;
        });
    }

    public function dispatchAfterCommit(AccountingOutbox $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                $this->dispatch((string) $event->id);
            } catch (Throwable $exception) {
                // La intención ya está confirmada; un fallo del publicador no debe repetir el cobro.
                Log::error('accounting.outbox.dispatch_unavailable', [
                    'event_id' => $event->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function dispatch(string $id): bool
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('La publicación Redis debe ocurrir fuera de toda transacción SQL.');
        }

        $event = DB::transaction(function () use ($id): ?AccountingOutbox {
            $event = AccountingOutbox::withoutBusinessScope()
                ->whereKey($id)
                ->whereIn('status', ['pending', 'publishing', 'published'])
                ->where('available_at', '<=', now())
                ->lock('for update skip locked')
                ->first();

            if (! $event) {
                return null;
            }

            if ($event->publish_attempts >= config('scale.accounting_outbox.max_publish_attempts')) {
                $event->forceFill([
                    'status' => 'failed',
                    'lease_token' => null,
                    'last_error' => 'Agotado el presupuesto de publicación sin confirmación contable.',
                ])->save();

                return null;
            }

            $event->forceFill([
                'status' => 'publishing',
                'publish_attempts' => $event->publish_attempts + 1,
                'lease_token' => (string) Str::uuid(),
                'available_at' => now()->addSeconds(max(
                    (int) config('scale.accounting_outbox.redispatch_after'),
                    (int) config('queue.connections.redis.retry_after', 180),
                )),
            ])->save();

            return $event;
        });

        if (! $event) {
            return false;
        }

        try {
            $job = new PostSaleAccountingJob(
                (string) $event->business_id, (string) $event->sale_id,
            );
            Queue::connection()->push($job, '', $job->queue);

            // El consumidor puede terminar antes de que push() retorne.
            AccountingOutbox::withoutBusinessScope()->whereKey($event->id)
                ->where('lease_token', $event->lease_token)->where('status', 'publishing')
                ->update(['status' => 'published', 'published_at' => now()]);

            return true;
        } catch (Throwable $exception) {
            AccountingOutbox::withoutBusinessScope()->whereKey($event->id)
                ->where('lease_token', $event->lease_token)->where('status', 'publishing')
                ->update([
                    'status' => $event->publish_attempts >= config('scale.accounting_outbox.max_publish_attempts')
                        ? 'failed' : 'pending',
                    'available_at' => now()->addSeconds(config('scale.accounting_outbox.backoff')),
                    'lease_token' => null,
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                ]);
            Log::warning('accounting.outbox.publish_failed', [
                'event_id' => $event->id,
                'business_id' => $event->business_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function dispatchDue(?int $limit = null): array
    {
        $ids = AccountingOutbox::withoutBusinessScope()
            ->whereIn('status', ['pending', 'publishing', 'published'])
            ->where('available_at', '<=', now())
            ->orderBy('available_at')->orderBy('id')
            ->limit($limit ?? config('scale.accounting_outbox.batch_size'))
            ->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += (int) $this->dispatch((string) $id);
        }

        return ['selected' => $ids->count(), 'published' => $published];
    }

    public function process(Sale $sale): void
    {
        $event = $this->register($sale);
        if ($event->isTerminal()) {
            return;
        }

        try {
            AccountingService::createFromSale($sale);
        } catch (Throwable $exception) {
            try {
                DB::transaction(function () use ($event, $exception): void {
                    $current = AccountingOutbox::withoutBusinessScope()
                        ->where('business_id', $event->business_id)
                        ->whereKey($event->id)->lockForUpdate()->firstOrFail();
                    if ($current->isTerminal()) {
                        return;
                    }
                    $attempts = $current->processing_attempts + 1;
                    $current->forceFill([
                        'processing_attempts' => $attempts,
                        'status' => $attempts >= config('scale.accounting_outbox.max_processing_attempts')
                            ? 'failed' : 'pending',
                        'lease_token' => null,
                        'available_at' => now()->addSeconds(config('scale.accounting_outbox.backoff')),
                        'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                    ])->save();
                });
            } catch (Throwable $storageException) {
                // El lease confirmado permite recuperar incluso si MySQL no registra el fallo.
                Log::error('accounting.outbox.failure_storage_unavailable', [
                    'event_id' => $event->id,
                    'error' => $storageException->getMessage(),
                ]);
            }

            throw $exception;
        }
    }

    public function retryFailed(string $id, string $businessId): bool
    {
        return DB::transaction(function () use ($id, $businessId): bool {
            $event = AccountingOutbox::withoutBusinessScope()
                ->where('business_id', $businessId)->whereKey($id)
                ->where('status', 'failed')->lockForUpdate()->first();
            if (! $event) {
                return false;
            }
            $event->forceFill([
                'status' => 'pending',
                'publish_attempts' => 0,
                'processing_attempts' => 0,
                'manual_retries' => $event->manual_retries + 1,
                'lease_token' => null,
                'available_at' => now(),
            ])->save();

            return true;
        });
    }
}
