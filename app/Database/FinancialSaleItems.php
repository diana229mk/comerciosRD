<?php

namespace App\Database;

use App\Services\SaleFinancialIntegrity;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FinancialSaleItems extends HasMany
{
    public function createMany(iterable $records)
    {
        $records = is_array($records) ? $records : iterator_to_array($records);

        return app(SaleFinancialIntegrity::class)->itemBatch(
            $this->parent, [], fn () => parent::createMany($records),
        );
    }

    public function saveMany($models)
    {
        $models = is_array($models) ? $models : iterator_to_array($models);

        return app(SaleFinancialIntegrity::class)->itemBatch(
            $this->parent, $models, fn () => parent::saveMany($models),
        );
    }
}
