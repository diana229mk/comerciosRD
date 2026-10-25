<?php

namespace App\Exports;

use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TopProductsSheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function collection(): Collection
    {
        $from = $this->dateFrom.' 00:00:00';
        $to = $this->dateTo.' 23:59:59';

        return SaleItem::query()
            ->select(
                'product_name',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(subtotal) as total_revenue')
            )
            ->whereHas('sale', function ($query) use ($from, $to) {
                $query->completed()
                    ->where(function ($q) use ($from, $to) {
                        $q->whereBetween('sale_date', [$from, $to])
                            ->orWhereBetween('sold_at', [$from, $to]);
                    });
            })
            ->groupBy('product_name')
            ->orderByDesc('total_quantity')
            ->limit(50)
            ->get()
            ->map(fn ($item) => [
                'producto' => (string) $item->product_name,
                'cantidad' => (float) $item->total_quantity,
                'ingresos' => round((float) $item->total_revenue, 2),
            ]);
    }

    public function headings(): array
    {
        return ['Producto', 'Cantidad Vendida', 'Ingresos'];
    }

    public function title(): string
    {
        return 'Productos Más Vendidos';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
