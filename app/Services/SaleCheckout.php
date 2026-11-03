<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\BusinessRole;
use BackedEnum;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaleCheckout
{
    private array $active = [];

    public function isActive(string $businessId): bool
    {
        return isset($this->active[$this->scope($businessId)]);
    }

    public function run(string $businessId, string $key, array $command, callable $checkout): Sale
    {
        app(SaleFinancialIntegrity::class)->assertBusiness($businessId);
        $user = auth()->user();
        if ($user && (($user->is_active ?? true) === false
            || ! $user->belongsToBusiness($businessId)
            || ! BusinessRole::allows($user, 'sales.create', $businessId))) {
            abort(403);
        }
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9:_-]{0,99}\z/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Clave de solicitud inválida.']);
        }
        $hash = self::fingerprint(['actor' => $user?->getAuthIdentifier(), 'command' => $command]);
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Checkout requiere la conexión MySQL de la venta.');
        }
        if ($this->isActive($businessId)) {
            throw new \LogicException('No se permite anidar solicitudes de checkout.');
        }

        return $connection->transaction(function () use ($businessId, $key, $hash, $checkout, $connection): Sale {
            try {
                $connection->table('sale_checkout_requests')->insert([
                    'id' => (string) Str::uuid(), 'business_id' => $businessId,
                    'request_key' => $key, 'payload_hash' => $hash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062
                    || ! str_contains($exception->getMessage(), 'sale_checkout_business_key_unique')) {
                    throw $exception;
                }
            }
            $request = $connection->table('sale_checkout_requests')
                ->where('business_id', $businessId)->where('request_key', $key)
                ->lockForUpdate()->firstOrFail();
            if (! hash_equals($request->payload_hash, $hash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave ya pertenece a otra solicitud; no se repitió la venta.',
                ]);
            }
            if ($request->sale_id !== null) {
                $sale = Sale::withoutBusinessScope()->where('business_id', $businessId)
                    ->whereKey($request->sale_id)->firstOrFail();
                if (auth()->check()) {
                    Gate::authorize('view', $sale);
                }
                if ($sale->isCreditSale()) {
                    app(SaleCreditService::class)->assertReceivable($sale);
                }

                return $sale->load(['items', 'customer', 'cashier', 'creator']);
            }

            $scope = $this->scope($businessId);
            $this->active[$scope] = true;
            try {
                $sale = $checkout();
                if (! $sale instanceof Sale || ! $sale->exists
                    || $sale->getConnection() !== $connection || (string) $sale->business_id !== $businessId) {
                    throw new \LogicException('Checkout no devolvió una venta persistida de la misma conexión/empresa.');
                }
                if ($connection->table('sale_checkout_requests')
                    ->where('business_id', $businessId)->where('id', $request->id)->update([
                        'sale_id' => $sale->id, 'updated_at' => now(),
                    ]) !== 1) {
                    throw new \RuntimeException('No se pudo confirmar la identidad del checkout.');
                }

                return $sale;
            } finally {
                unset($this->active[$scope]);
            }
        }, $connection->transactionLevel() === 0 ? 3 : 1);
    }

    public static function fingerprint(array $command): string
    {
        return hash('sha256', json_encode(self::canonical($command), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('La solicitud contiene un número no finito.');
        }
        if (! is_array($value)) {
            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('La solicitud contiene un valor no permitido.');
            }

            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    private function scope(string $businessId): string
    {
        $connection = DB::connection();

        return spl_object_id($connection).':'.$connection->getTablePrefix().':'.$businessId;
    }
}
