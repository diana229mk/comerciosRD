<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Importa lista de precios de un proveedor desde Excel.
 * Columnas esperadas (case-insensitive, con o sin acentos):
 *   - sku o codigo | codigo_proveedor | costo | lead_time (opcional) | preferido (opcional)
 *
 * Requiere pasar el $supplierId en el constructor.
 */
class SupplierPriceListImport implements ToCollection, WithHeadingRow
{
    public int $matched = 0;
    public int $updated = 0;
    public int $notFound = 0;
    public int $skipped = 0;

    public function __construct(protected string $supplierId) {}

    public function collection(Collection $rows): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant && app()->bound('currentBusinessId')) {
            $tenant = \App\Models\Business::find(app('currentBusinessId'));
        }
        if (! $tenant) return;

        $supplier = Supplier::find($this->supplierId);
        if (! $supplier) return;

        foreach ($rows as $row) {
            $row = $this->normalizeRow($row);

            $sku = trim((string) ($row['sku'] ?? $row['codigo'] ?? ''));
            $barcode = trim((string) ($row['codigo_de_barras'] ?? ''));
            $supplierCode = trim((string) ($row['codigo_proveedor'] ?? ''));
            $cost = $this->toFloat($row['costo'] ?? $row['precio_proveedor'] ?? 0);

            if ($cost <= 0) {
                $this->skipped++;
                continue;
            }

            // Buscar producto por SKU o barcode
            $product = null;
            if ($sku !== '') $product = Product::where('sku', $sku)->first();
            if (! $product && $barcode !== '') $product = Product::where('barcode', $barcode)->first();

            if (! $product) {
                $this->notFound++;
                continue;
            }

            $this->matched++;

            $leadTime = (int) ($row['lead_time'] ?? $row['lead_time_dias'] ?? 0);
            $preferido = strtolower(trim((string) ($row['preferido'] ?? '')));
            $isPreferred = in_array($preferido, ['si', 'sí', 'yes', '1', 'true'], true);

            $ps = ProductSupplier::firstOrNew([
                'product_id' => $product->id,
                'supplier_id' => $supplier->id,
            ]);

            $ps->last_cost = $cost;
            if ($supplierCode !== '') $ps->supplier_code = $supplierCode;
            if ($leadTime > 0) $ps->lead_time_days = $leadTime;
            if ($isPreferred) $ps->is_preferred = true;
            $ps->save();

            $this->updated++;
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
            $out[Str::slug((string) $key, '_')] = $value;
        }
        $aliases = [
            'code' => 'sku',
            'barcode' => 'codigo_de_barras',
            'codigo_barras' => 'codigo_de_barras',
            'cost' => 'costo',
            'price' => 'costo',
            'supplier_code' => 'codigo_proveedor',
        ];
        foreach ($aliases as $from => $to) {
            if (! array_key_exists($to, $out) && array_key_exists($from, $out)) {
                $out[$to] = $out[$from];
            }
        }
        return $out;
    }

    protected function toFloat(mixed $value): float
    {
        if (is_string($value)) $value = str_replace([',', ' '], ['.', ''], $value);
        return round((float) $value, 4);
    }
}
