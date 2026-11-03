<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\Sale;
use App\Scopes\BranchScope;
use App\Support\BusinessRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaleCreditService
{
    private array $writes = [];

    public static function cents(mixed $amount): int
    {
        if (! preg_match('/\A(\d{1,12})(?:\.(\d{1,2}))?\z/D', (string) $amount, $parts)) {
            throw new \InvalidArgumentException('Importe decimal inválido para CxC.');
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function decimal(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('El principal no puede ser negativo.');
        }

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public function prepare(Sale $sale): void
    {
        $this->assertConnection($sale);
        $user = auth()->user();
        if ($user && (($user->is_active ?? true) === false
            || ! $user->belongsToBusiness((string) $sale->business_id)
            || ! BusinessRole::allows($user, 'sales.create', (string) $sale->business_id)
            || ($sale->user_id && (string) $sale->user_id !== (string) $user->id)
            || ($sale->created_by && (string) $sale->created_by !== (string) $user->id))) {
            abort(403);
        }
        $this->assertCustomer($sale, requireModule: true);
        if (self::cents($sale->amount_paid ?? '0') !== 0) {
            $this->reject('Una venta a crédito nueva no puede registrar un cobro inicial.');
        }
        $sale->setAttribute('credit_version', 1);
    }

    public function seal(Sale $sale): void
    {
        $sale = $this->lockSale($sale);
        if (! $sale->isCompleted() || ! $sale->isCreditSale()) {
            return;
        }
        if ((int) $sale->credit_version !== 1) {
            $this->reject('La venta no tiene un origen CxC verificable; requiere revisión del legado.');
        }
        if (! $sale->items()->where('business_id', $sale->business_id)->exists()) {
            $this->reject('La venta a crédito requiere líneas persistidas.');
        }
        $customer = $this->assertCustomer($sale, requireModule: true);
        app(AccountingOutboxService::class)->register($sale);
        $this->lockEvent($sale);
        $existing = Receivable::withoutBusinessScope()->withTrashed()
            ->where('business_id', $sale->business_id)->where('sale_id', $sale->id)->lockForUpdate()->first();
        if ($existing) {
            $this->validate($sale, $existing);

            return;
        }
        if (! app(SaleFinancialIntegrity::class)->isConstructing($sale)) {
            $this->reject('Una CxC ausente no puede recrearse fuera de la construcción inicial.');
        }
        $number = $sale->sale_number ?: $sale->number ?: $sale->id;
        $amount = self::decimal(self::cents($sale->total));
        $receivable = new Receivable;
        $receivable->setConnection($sale->getConnectionName());
        $receivable->forceFill([
            'business_id' => $sale->business_id, 'sale_id' => $sale->id,
            'customer_id' => $customer->id, 'customer_name' => $sale->customer_name ?? $customer->name,
            'reference' => 'Venta '.$number, 'description' => 'Crédito venta #'.$number,
            'original_amount' => $amount, 'amount' => $amount,
            'paid_amount' => '0.00', 'balance' => $amount,
            'status' => self::cents($amount) === 0 ? 'pagada' : 'pendiente',
            'due_date' => ($sale->sale_date ?? $sale->sold_at ?? now())->copy()->addDays(7)->toDateString(),
            'notes' => 'Generado automáticamente desde venta',
            'created_by' => $sale->created_by ?? $sale->user_id,
        ]);
        $this->write($receivable, 'create', function () use ($receivable): void {
            if ($receivable->save() === false) {
                $this->reject('El guardado de CxC fue rechazado.');
            }
        });
        $created = $this->assertReceivable($sale);
        if (self::cents($created->paid_amount) !== 0 || self::cents($created->amount) !== self::cents($sale->total)) {
            $this->reject('La CxC inicial no conserva el principal y saldo completos.');
        }
    }

    public function assertReceivable(Sale $sale): Receivable
    {
        return $sale->getConnection()->transaction(function () use ($sale): Receivable {
            $sale = $this->lockSale($sale);
            $this->lockEvent($sale);
            $receivable = Receivable::withoutBusinessScope()->withTrashed()
                ->where('business_id', $sale->business_id)->where('sale_id', $sale->id)
                ->lockForUpdate()->first();
            if (! $receivable) {
                $this->reject('La venta a crédito no tiene su CxC; no se generará ni reparará automáticamente.');
            }
            $this->validate($sale, $receivable);

            return $receivable;
        });
    }

    public function complete(Sale $sale, callable $effects): void
    {
        $sale->getConnection()->transaction(function () use ($sale, $effects): void {
            $locked = $this->lockSale($sale);
            if (! $locked->isCompleted()) {
                return;
            }
            $this->assertReceivable($locked);
            if ($locked->credit_effects_at !== null) {
                return;
            }
            $effects($locked);
            $this->assertReceivable($locked);
            if ($locked->getConnection()->table('sales')->where('business_id', $locked->business_id)
                ->where('id', $locked->id)->update(['credit_effects_at' => now()]) !== 1) {
                $this->reject('No se pudo confirmar el procesamiento del crédito.');
            }
        });
    }

    public function refund(Sale $sale, string $amount): Receivable
    {
        $sale->assertBelongsToCurrentBusiness();
        if (! app(SaleService::class)->canReturn($sale)
            || ! auth()->user()->belongsToBusiness((string) $sale->business_id)) {
            abort(403, 'No tienes permiso para ajustar la CxC de una devolución.');
        }

        return $sale->getConnection()->transaction(function () use ($sale, $amount): Receivable {
            if (! $this->lockSale($sale)->isCompleted()) {
                $this->reject('Solo se puede ajustar una devolución de una venta completada.');
            }
            $receivable = $this->assertReceivable($sale);
            $receivable->amount = self::decimal(max(0, self::cents($receivable->amount) - self::cents($amount)));
            $receivable->balance = self::decimal(max(0,
                self::cents($receivable->amount) - self::cents($receivable->paid_amount)));
            $receivable->status = self::cents($receivable->balance) === 0 ? 'pagada'
                : (self::cents($receivable->paid_amount) > 0 ? 'parcial'
                    : ($receivable->due_date?->isBefore(today()) ? 'vencida' : 'pendiente'));
            $receivable->notes = trim(($receivable->notes ?: '')."\nDevolución: RD$ ".$amount.' el '.now()->format('d/m/Y H:i'));
            $this->write($receivable, 'refund', function () use ($receivable): void {
                if ($receivable->saveQuietly() === false) {
                    $this->reject('No se pudo ajustar la CxC.');
                }
            });
            $this->refreshPayments($receivable);

            return $receivable->refresh();
        });
    }

    public function refreshPayments(Receivable $receivable): void
    {
        $receivable->getConnection()->transaction(function () use ($receivable): void {
            $sale = (new Sale)->setConnection($receivable->getConnectionName());
            $sale->forceFill(['id' => $receivable->sale_id, 'business_id' => $receivable->business_id]);
            $locked = $this->assertReceivable($sale);
            $paid = 0;
            $payments = $locked->payments()->forBusiness((string) $locked->business_id)
                ->reorder()->orderBy('id')->lockForUpdate()->get();
            foreach ($payments as $payment) {
                $paid += self::cents($payment->amount);
            }
            $balance = max(0, self::cents($locked->amount) - $paid);
            $locked->forceFill([
                'paid_amount' => self::decimal($paid), 'balance' => self::decimal($balance),
                'status' => $balance === 0 ? 'pagada' : ($paid > 0 ? 'parcial'
                    : ($locked->due_date?->isBefore(today()) ? 'vencida' : 'pendiente')),
            ]);
            $this->write($locked, 'balance', function () use ($locked): void {
                if ($locked->saveQuietly() === false) {
                    $this->reject('No se pudo actualizar el saldo de CxC.');
                }
            });
            $receivable->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function mode(Receivable $receivable): ?string
    {
        foreach (array_reverse($this->writes) as [$model, $mode]) {
            if ($model === $receivable) {
                return $mode;
            }
        }

        return null;
    }

    private function write(Receivable $receivable, string $mode, callable $write): void
    {
        $this->writes[] = [$receivable, $mode];
        try {
            $write();
        } finally {
            array_pop($this->writes);
        }
    }

    private function validate(Sale $sale, Receivable $receivable): void
    {
        $balance = max(0, self::cents($receivable->amount) - self::cents($receivable->paid_amount));
        $validStatus = $balance === 0 ? $receivable->status === 'pagada'
            : (self::cents($receivable->paid_amount) > 0 ? $receivable->status === 'parcial'
                : in_array($receivable->status, ['pendiente', 'vencida'], true));
        if ((int) $sale->credit_version !== 1 || ! $sale->isCreditSale() || $receivable->trashed()
            || (string) $receivable->business_id !== (string) $sale->business_id
            || (string) $receivable->sale_id !== (string) $sale->id
            || ! $sale->customer_id || (string) $receivable->customer_id !== (string) $sale->customer_id
            || ! $sale->getConnection()->table('customers')->where('business_id', $sale->business_id)
                ->where('id', $sale->customer_id)->exists()
            || self::cents($receivable->original_amount) !== self::cents($sale->total)
            || self::cents($receivable->amount) > self::cents($receivable->original_amount)
            || self::cents($receivable->balance) !== $balance || ! $validStatus) {
            $this->reject('La identidad o los importes de CxC no coinciden con la venta.');
        }
    }

    private function assertCustomer(Sale $sale, bool $requireModule): Customer
    {
        app(SaleFinancialIntegrity::class)->assertBusiness((string) $sale->business_id);
        $business = Business::query()->sharedLock()->findOrFail($sale->business_id);
        if (! $business->is_active) {
            $this->reject('El negocio está inactivo.');
        }
        if ($requireModule && ! $business->hasModule('receivables')) {
            $this->reject('Este negocio no tiene habilitado CxC; no se permite vender a crédito.');
        }
        $customer = Customer::withoutBusinessScope()->where('business_id', $sale->business_id)
            ->whereKey($sale->customer_id)->where('is_active', true)->sharedLock()->first();
        if (! $customer) {
            $this->reject('Selecciona un cliente activo perteneciente al negocio.');
        }

        return $customer;
    }

    private function lockSale(Sale $sale): Sale
    {
        $this->assertConnection($sale);
        app(SaleFinancialIntegrity::class)->assertBusiness((string) $sale->business_id);

        // Receivables are business-global; entrypoints still authorize sale access separately.
        return Sale::withoutBusinessScope()->withoutGlobalScope(BranchScope::class)
            ->where('business_id', $sale->business_id)
            ->whereKey($sale->id)->lockForUpdate()->firstOrFail();
    }

    private function lockEvent(Sale $sale): void
    {
        $event = $sale->getConnection()->table('accounting_outbox')->where('business_id', $sale->business_id)
            ->where('sale_id', $sale->id)->lockForUpdate()->first();
        if (! $event) {
            $this->reject('La venta a crédito requiere su evento outbox persistido.');
        }
    }

    private function assertConnection(Sale $sale): void
    {
        if ($sale->getConnection() !== DB::connection() || $sale->getConnection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Venta, CxC y outbox requieren la misma conexión MySQL.');
        }
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['sale' => $message]);
    }
}
