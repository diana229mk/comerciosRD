<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SalesReportExport implements WithMultipleSheets
{
    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function sheets(): array
    {
        return [
            new SalesSummarySheet($this->dateFrom, $this->dateTo),
            new TopProductsSheet($this->dateFrom, $this->dateTo),
        ];
    }
}
