<?php

namespace App\Imports;

use App\Enums\InventoryBehavior;
use App\Models\Product;
use App\Models\ProductCategory;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Importa productos desde Excel (misma plantilla que ProductsExport).
 *
 * WithHeadingRow convierte encabezados a slug:
 *  "Código de Barras" → codigo_de_barras
 *  "Stock Mínimo" → stock_minimo
 *  etc.
 *
 * Upsert por código de barras (dentro del tenant). Sin barcode → siempre crea.
 */
class ProductsImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant && app()->bound('currentBusinessId')) {
            $tenant = \App\Models\Business::find(app('currentBusinessId'));
        }
        if (! $tenant) {
            return;
        }

        foreach ($rows as $row) {
            $row = $this->normalizeRow($row);

            $name = trim((string) ($row['nombre'] ?? ''));
            if ($name === '') {
                $this->skipped++;

                continue;
            }

            $categoryId = $this->resolveCategoryId($row['categoria'] ?? null);

            $behaviorRaw = strtolower(trim((string) ($row['comportamiento_inventario'] ?? 'descuenta al vender')));
            $behavior = (str_contains($behaviorRaw, 'no descuenta')
                || $behaviorRaw === 'do_not_deduct')
                ? InventoryBehavior::DoNotDeduct
                : InventoryBehavior::DeductOnSale;

            $activoRaw = strtolower(trim((string) ($row['activo'] ?? 'sí')));
            $isActive = ! in_array($activoRaw, ['no', '0', 'false', 'inactivo', 'n'], true);

            $barcode = trim((string) ($row['codigo_de_barras'] ?? ''));
            $barcode = $barcode !== '' ? $barcode : null;

            $sku = trim((string) ($row['sku'] ?? ''));
            $sku = $sku !== '' ? $sku : null;

            $unit = trim((string) ($row['unidad'] ?? 'unidad'));
            if ($unit === '') {
                $unit = 'unidad';
            }

            $description = trim((string) ($row['descripcion'] ?? ''));
            $description = $description !== '' ? $description : null;

            $data = [
                'name' => $name,
                'barcode' => $barcode,
                'sku' => $sku,
                'category_id' => $categoryId,
                'price' => $this->toFloat($row['precio'] ?? 0),
                'cost' => $this->toFloat($row['costo'] ?? 0),
                'stock' => $this->toFloat($row['stock'] ?? 0),
                'min_stock' => $this->toFloat($row['stock_minimo'] ?? 0),
                'unit' => $unit,
                'inventory_behavior' => $behavior,
                'track_stock' => true,
                'is_active' => $isActive,
                'description' => $description,
            ];

            // Campos de inventario avanzado (ferretería/repuestos/tienda)
            if (method_exists($tenant, 'usesAdvancedInventory') && $tenant->usesAdvancedInventory()) {
                $brand = trim((string) ($row['marca'] ?? ''));
                if ($brand !== '') $data['brand'] = $brand;

                $supplierCode = trim((string) ($row['codigo_proveedor'] ?? ''));
                if ($supplierCode !== '') $data['supplier_code'] = $supplierCode;

                $maxStock = $this->toFloat($row['stock_maximo'] ?? 0);
                if ($maxStock > 0) $data['max_stock'] = $maxStock;

                $aliases = trim((string) ($row['sinonimos'] ?? ''));
                if ($aliases !== '') {
                    $data['search_aliases'] = array_values(array_filter(array_map('trim', explode('|', $aliases))));
                }

                $isKit = strtolower(trim((string) ($row['es_kit'] ?? '')));
                if (in_array($isKit, ['si', 'sí', 'yes', '1', 'true'], true)) $data['is_kit'] = true;

                $tracksSerial = strtolower(trim((string) ($row['rastrea_serie'] ?? '')));
                if (in_array($tracksSerial, ['si', 'sí', 'yes', '1', 'true'], true)) $data['tracks_serial'] = true;
            }

            $product = null;
            if ($barcode) {
                $product = Product::query()
                    ->where('barcode', $barcode)
                    ->first();
            }

            if ($product) {
                $product->update($data);
                $this->updated++;
            } else {
                // business_id lo fuerza BelongsToBusiness (tenant actual)
                Product::create($data);
                $this->created++;
            }
        }
    }

    /**
     * @param  array<string, mixed>|Collection  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array|Collection $row): array
    {
        $arr = $row instanceof Collection ? $row->toArray() : $row;
        $out = [];

        foreach ($arr as $key => $value) {
            $normalized = Str::slug((string) $key, '_');
            $out[$normalized] = $value;
        }

        // Alias comunes por si el Excel usa otros encabezados
        $aliases = [
            'codigo_barras' => 'codigo_de_barras',
            'barcode' => 'codigo_de_barras',
            'category' => 'categoria',
            'price' => 'precio',
            'cost' => 'costo',
            'min_stock' => 'stock_minimo',
            'unit' => 'unidad',
            'inventory_behavior' => 'comportamiento_inventario',
            'active' => 'activo',
            'description' => 'descripcion',
        ];

        foreach ($aliases as $from => $to) {
            if (! array_key_exists($to, $out) && array_key_exists($from, $out)) {
                $out[$to] = $out[$from];
            }
        }

        return $out;
    }

    protected function resolveCategoryId(mixed $categoryName): ?string
    {
        $categoryName = trim((string) ($categoryName ?? ''));
        if ($categoryName === '') {
            return null;
        }

        $category = ProductCategory::query()
            ->where('name', $categoryName)
            ->first();

        if ($category) {
            return (string) $category->getKey();
        }

        $category = ProductCategory::create([
            'name' => $categoryName,
            'is_active' => true,
        ]);

        return (string) $category->getKey();
    }

    protected function toFloat(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace([',', ' '], ['.', ''], $value);
        }

        return round((float) $value, 3);
    }
}
