<?php

namespace App\Exports;

use App\Models\Sale;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesSummarySheet implements FromCollection, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function collection(): Collection
    {
        $from = $this->dateFrom.' 00:00:00';
        $to = $this->dateTo.' 23:59:59';

        $sales = Sale::query()
            ->completed()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('sale_date', [$from, $to])
                    ->orWhereBetween('sold_at', [$from, $to]);
            })
            ->get();

        $count = $sales->count();
        $total = (float) $sales->sum('total');

        return collect([
            [
                'total_ventas' => round($total, 2),
                'cantidad' => $count,
                'ticket_promedio' => $count > 0 ? round($total / $count, 2) : 0,
                'itbis' => round((float) $sales->sum('tax'), 2),
                'descuentos' => round((float) $sales->sum('discount'), 2),
                'efectivo' => round((float) $sales->filter(function (Sale $s) {
                    $pm = $s->payment_method;
                    $value = is_object($pm) ? $pm->value : (string) $pm;

                    return in_array($value, ['efectivo', 'cash'], true);
                })->sum('total'), 2),
                'tarjeta' => round((float) $sales->filter(function (Sale $s) {
                    $pm = $s->payment_method;
                    $value = is_object($pm) ? $pm->value : (string) $pm;

                    return in_array($value, ['tarjeta', 'card'], true);
                })->sum('total'), 2),
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'Total Ventas',
            'Cantidad de Ventas',
            'Ticket Promedio',
            'ITBIS',
            'Descuentos',
            'Ventas Efectivo',
            'Ventas Tarjeta',
        ];
    }

    public function title(): string
    {
        return 'Resumen de Ventas';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
