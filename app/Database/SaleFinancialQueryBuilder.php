<?php

namespace App\Database;

use App\Services\SaleFinancialIntegrity;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Validation\ValidationException;

final class SaleFinancialQueryBuilder extends Builder
{
    public function __construct(
        ConnectionInterface $connection,
        Grammar $grammar,
        Processor $processor,
        private readonly string $financialTable,
    ) {
        parent::__construct($connection, $grammar, $processor);
    }

    public function update(array $values)
    {
        return app(SaleFinancialIntegrity::class)->queryWrite(
            $this, $this->financialTable, 'update', $values,
            fn (self $query) => $query->updateUnchecked($values),
        );
    }

    public function delete($id = null)
    {
        $query = clone $this;
        if ($id !== null) {
            $query->where($this->financialTable.'.id', $id);
        }

        return app(SaleFinancialIntegrity::class)->queryWrite(
            $query, $this->financialTable, 'delete', [],
            fn (self $locked) => $locked->deleteUnchecked(),
        );
    }

    public function insert(array $values)
    {
        if ($values === []) {
            return true;
        }

        return app(SaleFinancialIntegrity::class)->queryWrite(
            $this, $this->financialTable, 'insert', $values,
            fn (self $query) => $query->insertUnchecked($values),
        );
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return app(SaleFinancialIntegrity::class)->queryWrite(
            $this, $this->financialTable, 'insert', $values,
            fn (self $query) => $query->insertIdUnchecked($values, $sequence),
        );
    }

    public function upsert(array $values, array|string $uniqueBy, ?array $update = null)
    {
        $this->unsupported();
    }

    public function insertOrIgnore(array $values)
    {
        $this->unsupported();
    }

    public function insertUsing(array $columns, $query)
    {
        $this->unsupported();
    }

    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $this->unsupported();
    }

    public function updateFrom(array $values)
    {
        $this->unsupported();
    }

    public function truncate()
    {
        $this->unsupported();
    }

    private function updateUnchecked(array $values)
    {
        return parent::update($values);
    }

    private function deleteUnchecked()
    {
        return parent::delete();
    }

    private function insertUnchecked(array $values)
    {
        return parent::insert($values);
    }

    private function insertIdUnchecked(array $values, $sequence)
    {
        return parent::insertGetId($values, $sequence);
    }

    private function unsupported(): never
    {
        throw ValidationException::withMessages([
            'sale' => 'Esta escritura masiva no está permitida para ventas. Utiliza los modelos protegidos.',
        ]);
    }
}
