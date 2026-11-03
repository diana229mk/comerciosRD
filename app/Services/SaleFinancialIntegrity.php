<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Observers\SaleObserver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class SaleFinancialIntegrity
{
    public const FINANCIAL_FIELDS = [
        'id', 'business_id', 'branch_id', 'sale_number', 'number',
        'customer_id', 'customer_name', 'prescription_id', 'prescription_ref',
        'subtotal', 'tax', 'tax_rate', 'discount', 'total', 'payment_method',
        'amount_paid', 'amount_tendered', 'change_amount', 'change', 'change_due',
        'created_by', 'user_id', 'sale_date', 'sold_at', 'price_list_id', 'quote_id',
        'deleted_at',
        'credit_version', 'credit_effects_at',
    ];

    private array $construction = [];

    private array $writes = [];

    public function construct(Sale $sale, callable $build): mixed
    {
        if ($sale->exists) {
            $this->reject();
        }

        return $sale->getConnection()->transaction(function () use ($sale, $build) {
            $sale->business_id = $sale->business_id ?: Sale::resolveCurrentBusinessId();
            $sale->setUniqueIds();
            $key = $this->constructionKey($sale->getConnection(), (string) $sale->getKey());
            $this->construction[$key] = (string) $sale->business_id;

            try {
                if ($sale->isCreditSale()) {
                    app(SaleCreditService::class)->prepare($sale);
                }
                $sale->save();
                if (! $sale->exists) {
                    $this->reject();
                }
                $result = $build($sale);
                $this->seal($sale);
                if ($sale->isCreditSale() && $sale->isCompleted()) {
                    app(SaleObserver::class)->processCompletedSale($sale);
                    app(SaleCreditService::class)->assertReceivable($sale);
                    $sale->refresh();
                }

                return $result;
            } finally {
                unset($this->construction[$key]);
            }
        });
    }

    public function seal(Sale $sale): void
    {
        if ($sale->isCreditSale() && $sale->isCompleted()) {
            app(SaleCreditService::class)->seal($sale);
        }
        unset($this->construction[$this->constructionKey($sale->getConnection(), (string) $sale->getKey())]);
    }

    public function isConstructing(Sale $sale): bool
    {
        return ($this->construction[$this->constructionKey($sale->getConnection(), (string) $sale->id)] ?? null)
            === (string) $sale->business_id;
    }

    public function itemBatch(Sale $parent, array $models, callable $write): mixed
    {
        return $parent->getConnection()->transaction(function () use ($parent, $models, $write) {
            $connection = $parent->getConnection();
            $businessId = (string) $parent->getRawOriginal('business_id');
            $this->assertBusiness($businessId);
            $ids = [(string) $parent->getKey()];
            foreach ($models as $model) {
                if ($model->exists) {
                    $row = $connection->table('sale_items')->where('business_id', $businessId)
                        ->where('id', $model->getRawOriginal('id'))->first();
                    if (! $row) {
                        abort(404);
                    }
                    $ids[] = (string) $row->sale_id;
                }
            }
            $ids = array_unique($ids);
            sort($ids, SORT_STRING);
            $parents = [];
            foreach ($ids as $id) {
                $parents[] = $this->lockSale($connection, $id, $businessId);
            }
            foreach ($parents as $row) {
                if ($this->frozen($connection, $row)) {
                    $this->reject();
                }
            }

            return $write();
        });
    }

    public function modelWrite(Model $model, string $operation, callable $write): mixed
    {
        return $model->getConnection()->transaction(function () use ($model, $operation, $write) {
            $connection = $model->getConnection();
            $businessId = (string) ($model->getRawOriginal('business_id')
                ?: $model->getAttribute('business_id') ?: Sale::resolveCurrentBusinessId());
            $this->assertBusiness($businessId);
            if (! $model->exists) {
                $model->setAttribute('business_id', $businessId);
            }
            $dirty = $model->getDirty();
            $id = (string) ($model->getRawOriginal('id') ?: $model->getKey());
            $parents = [];
            $original = null;
            $previousStatus = null;

            if ($model instanceof Sale) {
                if ($model->exists) {
                    $row = $this->lockSale($connection, $id, $businessId);
                    $previousStatus = $row->status;
                    $this->assertSaleUpdate($row, $dirty, $this->frozen($connection, $row), $operation);
                    $this->synchronize($model, (array) $row, $dirty);
                    if ($model->isCreditSale() && $model->isCompleted() && ! $this->isConstructing($model)
                        && ! in_array($previousStatus, ['completada', 'completed'], true)) {
                        app(SaleCreditService::class)->assertReceivable($model);
                    }
                } elseif ($model->isCreditSale() && ! $this->isConstructing($model)) {
                    $this->reject();
                } elseif (! $model->isCreditSale() && ($model->credit_version !== null || $model->credit_effects_at !== null)) {
                    $this->reject();
                }
            } else {
                $original = $model->exists
                    ? $connection->table('sale_items')->where('business_id', $businessId)->where('id', $id)->first()
                    : null;
                if ($model->exists && ! $original) {
                    abort(404);
                }
                $parents = array_values(array_unique(array_filter([
                    $original?->sale_id, $model->getAttribute('sale_id'),
                ])));
                sort($parents, SORT_STRING);
                $parentRows = [];
                foreach ($parents as $parentId) {
                    $parentRows[] = $this->lockSale($connection, (string) $parentId, $businessId);
                }
                foreach ($parentRows as $parent) {
                    if ($this->frozen($connection, $parent)) {
                        $this->reject();
                    }
                }
                if ($original) {
                    $row = $connection->table('sale_items')->where('business_id', $businessId)
                        ->where('id', $id)->lockForUpdate()->first();
                    if (! $row || $row->sale_id !== $original->sale_id) {
                        $this->reject();
                    }
                    $this->assertIdentity((array) $row, $dirty);
                    $this->synchronize($model, (array) $row, $dirty);
                }
            }

            $this->writes[] = ['model' => $model, 'parents' => $parents];
            try {
                $result = $write();
                if ($result === false) {
                    $this->reject();
                }
                if ($model instanceof Sale && $operation !== 'delete' && ! $this->isConstructing($model)) {
                    $persisted = Sale::withoutBusinessScope()->where('business_id', $businessId)
                        ->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                    if ($persisted->isCreditSale() && $persisted->isCompleted()) {
                        if ((int) $persisted->credit_version === 1
                            || ! in_array($previousStatus, ['completada', 'completed'], true)) {
                            app(SaleCreditService::class)->assertReceivable($persisted);
                        }
                        if (! in_array($previousStatus, ['completada', 'completed'], true)) {
                            app(AccountingOutboxService::class)->register($persisted, resumeSkipped: true);
                            app(SaleObserver::class)->processCompletedSale($persisted);
                        }
                    } elseif ($persisted->isCreditSale() && in_array($previousStatus, ['completada', 'completed'], true)) {
                        $connection->table('sales')->where('business_id', $businessId)->where('id', $persisted->id)
                            ->update(['credit_effects_at' => null]);
                    }
                }
                if ($model instanceof SaleItem && $original
                    && $original->sale_id !== $model->getAttribute('sale_id')) {
                    $row = $this->lockSale($connection, (string) $original->sale_id, $businessId);
                    (new Sale)->newFromBuilder((array) $row, $connection->getName())->recalculateTotals();
                }

                return $result;
            } finally {
                array_pop($this->writes);
            }
        });
    }

    public function queryWrite(Builder $query, string $table, string $operation, array $values, callable $write): mixed
    {
        if ($query->from !== $table || $query->joins || $query->unions
            || $query->groups || $query->havings || $query->limit !== null || $query->offset !== null) {
            $this->reject();
        }
        $connection = $query->getConnection();

        return $connection->transaction(function () use ($query, $table, $operation, $values, $write, $connection) {
            $locked = clone $query;
            if ($operation === 'insert') {
                $rows = isset($values[0]) ? $values : [$values];
                foreach ($rows as $row) {
                    $this->assertBusiness((string) ($row['business_id'] ?? ''));
                    if ($table === 'sale_items') {
                        $scope = $this->itemScope($connection, (string) ($row['id'] ?? ''));
                        $this->assertItem($connection, $row, $scope);
                    } elseif (! $this->saleScope($connection, (string) ($row['id'] ?? ''))) {
                        $this->reject();
                    }
                }

                return $write($locked);
            }

            if ($table === 'sale_items') {
                // The model wrapper already owns the parent locks before locking this line.
                $scope = $this->itemScope($connection);
                $locked->where($table.'.id', $scope['model']->getKey());
                $rows = (clone $locked)->select($table.'.*')->reorder()->lockForUpdate()->get();
                foreach ($rows as $row) {
                    $normalized = [];
                    foreach ($values as $column => $value) {
                        $field = $this->field($column);
                        if ($column !== $field && $column !== $table.'.'.$field) {
                            $this->reject();
                        }
                        $normalized[$field] = $value;
                    }
                    $this->assertIdentity((array) $row, $normalized);
                    $this->assertItem($connection, array_replace((array) $row, $normalized), $scope);
                }
            } else {
                $rows = (clone $locked)->select($table.'.*')->reorder()->get();
                $ids = $rows->pluck('id')->sort()->values()->all();
                $sales = [];
                foreach ($ids as $id) {
                    $candidate = $rows->firstWhere('id', $id);
                    $this->assertBusiness((string) $candidate->business_id);
                    $sales[] = $this->lockSale($connection, (string) $id, (string) $candidate->business_id);
                }
                foreach ($sales as $row) {
                    foreach (array_keys($values) as $column) {
                        if ($this->field($column) === 'status' && ! $this->saleScope($connection, (string) $row->id)) {
                            $this->reject();
                        }
                    }
                    $this->assertSaleUpdate($row, $values, $this->frozen($connection, $row), $operation);
                }
                // Do not let rows newly matching the predicate escape the inspected set.
                $locked->whereIn($table.'.id', $ids);
            }

            return $write($locked);
        });
    }

    public function isFrozen(Sale $sale): bool
    {
        if (! $sale->exists) {
            return false;
        }

        return $sale->getConnection()->transaction(function () use ($sale) {
            $businessId = (string) $sale->getRawOriginal('business_id');
            $this->assertBusiness($businessId);
            $row = $this->lockSale($sale->getConnection(), (string) $sale->getRawOriginal('id'), $businessId);

            return $this->frozen($sale->getConnection(), $row);
        });
    }

    public function assertSaleUpdate(object $row, array $values, bool $frozen, string $operation = 'update'): void
    {
        $this->assertIdentity((array) $row, $values);
        foreach ($values as $column => $value) {
            $field = $this->field($column);
            if (in_array($field, ['credit_version', 'credit_effects_at'], true)
                && ($value instanceof Expression || (string) ($row->{$field} ?? '') !== (string) $value)) {
                $this->reject();
            }
        }
        if (! $frozen) {
            return;
        }
        if ($operation === 'delete') {
            $this->reject();
        }
        $probe = (new Sale)->setRawAttributes((array) $row, true);
        foreach ($values as $column => $value) {
            $field = $this->field($column);
            if (in_array($field, self::FINANCIAL_FIELDS, true)) {
                if ($value instanceof Expression) {
                    $this->reject();
                }
                $probe->setAttribute($field, $value);
                if ($probe->isDirty($field)) {
                    $this->reject();
                }
            }
            if ($field === 'status' && ! in_array($value instanceof \BackedEnum ? $value->value : $value, [
                'completada', 'completed', 'anulada', 'cancelled', 'refunded',
            ], true)) {
                $this->reject();
            }
        }
    }

    private function frozen(Connection $connection, object $sale): bool
    {
        $key = $this->constructionKey($connection, (string) $sale->id);
        $constructing = ($this->construction[$key] ?? null) === (string) $sale->business_id;
        if (! $constructing && in_array($sale->status, ['completada', 'completed', 'anulada', 'cancelled', 'refunded'], true)) {
            return true;
        }
        $event = Schema::connection($connection->getName())->hasTable('accounting_outbox')
            ? $connection->table('accounting_outbox')->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)->lockForUpdate()->first()
            : null;
        if ($event && (! $constructing || $event->journal_entry_id !== null
            || in_array($event->status, ['processed', 'skipped'], true))) {
            return true;
        }
        if (Schema::connection($connection->getName())->hasTable('journal_entries')) {
            return $connection->table('journal_entries')->where('business_id', $sale->business_id)
                ->where('reference', 'Venta #'.($sale->sale_number ?: $sale->number ?: $sale->id))
                ->lockForUpdate()->first() !== null;
        }

        return false;
    }

    private function lockSale(Connection $connection, string $id, string $businessId): object
    {
        $this->assertBusiness($businessId);
        $row = $connection->table('sales')->where('business_id', $businessId)
            ->where('id', $id)->lockForUpdate()->first();
        if (! $row) {
            abort(404);
        }

        return $row;
    }

    public function assertBusiness(string $businessId): void
    {
        $current = Sale::resolveCurrentBusinessId();
        if ($businessId === '' || ($current !== null && $current !== $businessId)
            || ($current === null && (! app()->runningInConsole() || auth()->check()))) {
            abort(404);
        }
    }

    private function assertIdentity(array $row, array $values): void
    {
        foreach ($values as $column => $value) {
            $field = $this->field($column);
            if (in_array($field, ['id', 'business_id'], true)
                && ($value instanceof Expression || (string) ($row[$field] ?? '') !== (string) $value)) {
                $this->reject();
            }
        }
    }

    private function assertItem(Connection $connection, array $row, array $scope): void
    {
        $businessId = (string) ($row['business_id'] ?? '');
        $this->assertBusiness($businessId);
        if (! in_array($row['sale_id'] ?? null, $scope['parents'], true)) {
            $this->reject();
        }
        $parent = $this->lockSale($connection, (string) $row['sale_id'], $businessId);
        if ($this->frozen($connection, $parent)) {
            $this->reject();
        }
        if (! empty($row['product_id']) && ! $connection->table('products')
            ->where('business_id', $businessId)->where('id', $row['product_id'])->exists()) {
            abort(404);
        }
        if (! empty($row['variant_id']) && ! $connection->table('product_variants')
            ->where('business_id', $businessId)->where('product_id', $row['product_id'] ?? null)
            ->where('id', $row['variant_id'])->exists()) {
            abort(404);
        }
        if (! empty($row['parent_sale_item_id']) && ! $connection->table('sale_items')
            ->where('business_id', $businessId)->where('sale_id', $row['sale_id'])
            ->where('id', $row['parent_sale_item_id'])->exists()) {
            abort(404);
        }
    }

    private function itemScope(Connection $connection, ?string $id = null): array
    {
        foreach (array_reverse($this->writes) as $scope) {
            $model = $scope['model'];
            if ($model instanceof SaleItem && $model->getConnection() === $connection
                && ($id === null || (string) $model->getKey() === $id)) {
                return $scope;
            }
        }
        $this->reject();
    }

    private function saleScope(Connection $connection, string $id): bool
    {
        foreach ($this->writes as $scope) {
            $model = $scope['model'];
            if ($model instanceof Sale && $model->getConnection() === $connection
                && (string) $model->getKey() === $id) {
                return true;
            }
        }

        return false;
    }

    private function synchronize(Model $model, array $row, array $dirty): void
    {
        $model->setRawAttributes($row, true);
        $model->setRawAttributes(array_replace($row, $dirty));
        $model->unsetRelations();
    }

    private function constructionKey(Connection $connection, string $id): string
    {
        return spl_object_id($connection).':'.$connection->getTablePrefix().':'.$id;
    }

    private function field(string $column): string
    {
        return strtolower(explode('->', basename(str_replace('.', '/', $column)))[0]);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'sale' => 'La integridad financiera de la venta no permite esta operación. Recarga la venta.',
        ]);
    }
}
