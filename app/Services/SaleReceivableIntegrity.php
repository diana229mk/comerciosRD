<?php

namespace App\Services;

use App\Models\Receivable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Validation\ValidationException;

final class SaleReceivableIntegrity
{
    private const PROTECTED = [
        'id', 'business_id', 'sale_id', 'customer_id', 'customer_name', 'reference',
        'original_amount', 'amount', 'paid_amount', 'balance', 'status', 'deleted_at', 'created_by',
    ];

    private array $models = [];

    public function modelWrite(Receivable $model, string $operation, callable $write): mixed
    {
        return $model->getConnection()->transaction(function () use ($model, $operation, $write) {
            $businessId = (string) ($model->getRawOriginal('business_id') ?: $model->business_id
                ?: Receivable::resolveCurrentBusinessId());
            app(SaleFinancialIntegrity::class)->assertBusiness($businessId);
            if (! $model->exists) {
                $model->business_id = $businessId;
            }
            $dirty = $model->getDirty();
            $row = $model->exists ? $model->getConnection()->table('receivables')
                ->where('business_id', $businessId)->where('id', $model->getRawOriginal('id'))->first() : null;
            if ($model->exists && ! $row) {
                abort(404);
            }
            $saleId = $row->sale_id ?? $model->sale_id;
            if ($saleId !== null) {
                $source = $model->getConnection()->table('sales')->where('business_id', $businessId)
                    ->where('id', $saleId)->lockForUpdate()->first();
                if (! $source) {
                    abort(404);
                }
                $model->getConnection()->table('accounting_outbox')->where('business_id', $businessId)
                    ->where('sale_id', $saleId)->lockForUpdate()->first();
            }
            if ($row) {
                $row = $model->getConnection()->table('receivables')->where('business_id', $businessId)
                    ->where('id', $row->id)->lockForUpdate()->firstOrFail();
                $this->assertWrite($row, $dirty, $operation, app(SaleCreditService::class)->mode($model));
                $model->setRawAttributes((array) $row, true);
                $model->setRawAttributes(array_replace((array) $row, $dirty));
                $model->unsetRelations();
            } elseif ($model->sale_id !== null && app(SaleCreditService::class)->mode($model) !== 'create') {
                $this->reject();
            } elseif ($model->sale_id === null && $model->original_amount !== null) {
                $this->reject();
            }
            $this->models[] = $model;
            try {
                $result = $write();
                if ($result === false) {
                    $this->reject();
                }

                return $result;
            } finally {
                array_pop($this->models);
            }
        });
    }

    public function queryWrite(Builder $query, string $operation, array $values, callable $write): mixed
    {
        if ($query->from !== 'receivables' || $query->joins || $query->unions || $query->groups
            || $query->havings || $query->limit !== null || $query->offset !== null) {
            $this->reject();
        }

        return $query->getConnection()->transaction(function () use ($query, $operation, $values, $write) {
            $locked = clone $query;
            if ($operation === 'insert') {
                foreach (isset($values[0]) ? $values : [$values] as $row) {
                    $row = $this->normalize($row);
                    app(SaleFinancialIntegrity::class)->assertBusiness((string) ($row['business_id'] ?? ''));
                    $scope = $this->model($query, (string) ($row['id'] ?? ''));
                    if (($row['sale_id'] ?? null) !== null
                        && (! $scope || app(SaleCreditService::class)->mode($scope) !== 'create')) {
                        $this->reject();
                    }
                    if (($row['sale_id'] ?? null) === null && ($row['original_amount'] ?? null) !== null) {
                        $this->reject();
                    }
                }

                return $write($locked);
            }
            $values = $this->normalize($values);
            $rows = (clone $locked)->select('receivables.*')->reorder()->get();
            foreach ($rows->sortBy('id') as $row) {
                app(SaleFinancialIntegrity::class)->assertBusiness((string) $row->business_id);
                $scope = $this->model($query, (string) $row->id);
                // Linked writes must own Sale -> Outbox -> Receivable locks through the model.
                if ($row->sale_id !== null && ! $scope) {
                    $this->reject();
                }
                $current = $query->getConnection()->table('receivables')->where('business_id', $row->business_id)
                    ->where('id', $row->id)->lockForUpdate()->firstOrFail();
                $this->assertWrite($current, $values, $operation,
                    $scope ? app(SaleCreditService::class)->mode($scope) : null);
            }
            $locked->whereIn('receivables.id', $rows->pluck('id')->all());

            return $write($locked);
        });
    }

    private function assertWrite(object $row, array $values, string $operation, ?string $mode): void
    {
        foreach (['id', 'business_id', 'sale_id', 'original_amount'] as $field) {
            if (array_key_exists($field, $values)
                && ($values[$field] instanceof Expression || (string) $values[$field] !== (string) $row->{$field})) {
                $this->reject();
            }
        }
        if ($row->sale_id === null) {
            return;
        }
        if ($operation === 'delete') {
            $this->reject();
        }
        $allowed = match ($mode) {
            'refund' => ['amount', 'balance', 'status'],
            'balance' => ['paid_amount', 'balance', 'status'],
            default => [],
        };
        $probe = (new Receivable)->setRawAttributes((array) $row, true);
        foreach ($values as $field => $value) {
            if (! in_array($field, self::PROTECTED, true) || in_array($field, $allowed, true)) {
                continue;
            }
            if ($value instanceof Expression) {
                $this->reject();
            }
            $probe->setAttribute($field, $value);
            if ($probe->isDirty($field)) {
                $this->reject();
            }
        }
    }

    private function normalize(array $values): array
    {
        $normalized = [];
        foreach ($values as $column => $value) {
            if (! preg_match('/\A(?:receivables\.)?([a-z_][a-z0-9_]*)\z/iD', $column, $parts)) {
                $this->reject();
            }
            $field = strtolower($parts[1]);
            if (array_key_exists($field, $normalized)) {
                $this->reject();
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }

    private function model(Builder $query, string $id): ?Receivable
    {
        foreach (array_reverse($this->models) as $model) {
            if ($model->getConnection() === $query->getConnection() && (string) $model->id === $id) {
                return $model;
            }
        }

        return null;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'receivable' => 'La CxC de una venta solo admite cobros y ajustes autorizados; no se puede borrar ni desvincular.',
        ]);
    }
}
