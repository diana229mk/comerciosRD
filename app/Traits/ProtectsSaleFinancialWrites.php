<?php

namespace App\Traits;

use App\Database\SaleFinancialQueryBuilder;
use App\Services\SaleFinancialIntegrity;
use Illuminate\Validation\ValidationException;

trait ProtectsSaleFinancialWrites
{
    public static function destroy($ids)
    {
        throw ValidationException::withMessages([
            'sale' => 'La eliminación masiva no está permitida. Utiliza los modelos protegidos.',
        ]);
    }

    public function save(array $options = [])
    {
        return app(SaleFinancialIntegrity::class)->modelWrite(
            $this, 'update', fn () => parent::save($options),
        );
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return app(SaleFinancialIntegrity::class)->modelWrite(
            $this, 'delete', fn () => parent::delete(),
        );
    }

    protected function newBaseQueryBuilder()
    {
        $connection = $this->getConnection();

        return new SaleFinancialQueryBuilder(
            $connection, $connection->getQueryGrammar(), $connection->getPostProcessor(), $this->getTable(),
        );
    }
}
