<?php

namespace App\Exports;

use App\Enums\InventoryBehavior;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function query()
    {
        // Global Scope filtra por tenant actual
        return Product::query()
            ->with('category')
            ->orderBy('name');
    }

    public function headings(): array
    {
        return [
            'Nombre',
            'Código de Barras',
            'SKU',
            'Categoría',
            'Precio',
            'Costo',
            'Stock',
            'Stock Mínimo',
            'Unidad',
            'Comportamiento Inventario',
            'Activo',
            'Descripción',
        ];
    }

    /**
     * @param  Product  $product
     * @return array<int, mixed>
     */
    public function map($product): array
    {
        $behavior = $product->inventory_behavior;

        if ($behavior instanceof InventoryBehavior) {
            $behavior = $behavior->value;
        }

        return [
            $product->name,
            $product->barcode,
            $product->sku,
            $product->category?->name,
            $product->price,
            $product->cost,
            $product->stock,
            $product->min_stock,
            $product->unit,
            $behavior === InventoryBehavior::DoNotDeduct->value
                || $behavior === 'do_not_deduct'
                ? 'No descuenta'
                : 'Descuenta al vender',
            $product->is_active ? 'Sí' : 'No',
            $product->description,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
