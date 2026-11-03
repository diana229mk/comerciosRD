<?php

namespace App\Database;

use App\Services\SaleReceivableIntegrity;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

final class ReceivableQueryBuilder extends Builder
{
    public function update(array $values)
    {
        return app(SaleReceivableIntegrity::class)->queryWrite(
            $this, 'update', $values, fn (self $query) => $query->updateUnchecked($values),
        );
    }

    public function delete($id = null)
    {
        $query = clone $this;
        if ($id !== null) {
            $query->where('receivables.id', $id);
        }

        return app(SaleReceivableIntegrity::class)->queryWrite(
            $query, 'delete', [], fn (self $locked) => $locked->deleteUnchecked(),
        );
    }

    public function insert(array $values)
    {
        if ($values === []) {
            return true;
        }

        return app(SaleReceivableIntegrity::class)->queryWrite(
            $this, 'insert', $values, fn (self $query) => $query->insertUnchecked($values),
        );
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return app(SaleReceivableIntegrity::class)->queryWrite(
            $this, 'insert', $values, fn (self $query) => $query->insertIdUnchecked($values, $sequence),
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
        throw ValidationException::withMessages(['receivable' => 'Escritura masiva no soportada para CxC.']);
    }
}
