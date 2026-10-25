<?php

namespace App\Imports;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductSeason;
use App\Models\ProductVariant;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class RetailCollectionImport implements ToCollection, WithHeadingRow
{
    public int $created = 0;

    public int $updated = 0;

    public int $variants = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant && app()->bound('currentBusinessId')) {
            $tenant = Business::find(app('currentBusinessId'));
        }
        if (! $tenant) {
            return;
        }

        foreach ($rows as $row) {
            $row = $this->normalizeRow($row);
            $name = trim((string) ($row['nombre'] ?? $row['name'] ?? ''));
            if ($name === '') {
                $this->skipped++;
                continue;
            }

            $sku = trim((string) ($row['sku'] ?? '')) ?: null;
            $barcode = trim((string) ($row['codigo_de_barras'] ?? $row['barcode'] ?? '')) ?: null;
            $brand = trim((string) ($row['marca'] ?? $row['brand'] ?? '')) ?: null;
            $seasonName = trim((string) ($row['temporada'] ?? $row['season'] ?? '')) ?: null;
            $collection = trim((string) ($row['coleccion'] ?? $row['collection'] ?? '')) ?: null;
            $style = trim((string) ($row['estilo'] ?? $row['style'] ?? '')) ?: null;
            $size = trim((string) ($row['talla'] ?? $row['size'] ?? '')) ?: null;
            $color = trim((string) ($row['color'] ?? $row['colour'] ?? '')) ?: null;
            $price = $this->toFloat($row['precio'] ?? $row['price'] ?? 0);
            $cost = $this->toFloat($row['costo'] ?? $row['cost'] ?? 0);
            $stock = $this->toFloat($row['stock'] ?? $row['inventory'] ?? 0);
            $isActive = ! in_array(strtolower(trim((string) ($row['activo'] ?? $row['active'] ?? 'si'))), ['no', '0', 'false', 'inactivo', 'n'], true);
            $isLiquidation = in_array(strtolower(trim((string) ($row['liquidacion'] ?? $row['is_liquidation'] ?? 'no'))), ['si', 'sí', 'yes', '1', 'true'], true);

            $seasonId = null;
            if ($seasonName) {
                $seasonId = ProductSeason::query()->where('business_id', $tenant->id)->where('name', $seasonName)->value('id');
                if (! $seasonId) {
                    $seasonId = ProductSeason::create([
                        'business_id' => $tenant->id,
                        'name' => $seasonName,
                        'code' => Str::slug($seasonName),
                        'type' => 'collection',
                        'is_active' => true,
                    ])->id;
                }
            }

            $product = null;
            if ($sku) {
                $product = Product::query()->where('sku', $sku)->first();
            }
            if (! $product && $barcode) {
                $product = Product::query()->where('barcode', $barcode)->first();
            }
            if (! $product) {
                $product = Product::query()->where('name', $name)->where('brand', $brand)->first();
            }

            $data = [
                'name' => $name,
                'brand' => $brand,
                'sku' => $sku,
                'barcode' => $barcode,
                'season_id' => $seasonId,
                'collection' => $collection,
                'style' => $style,
                'price' => $price,
                'cost' => $cost,
                'stock' => $stock,
                'track_stock' => true,
                'is_active' => $isActive,
                'is_liquidation' => $isLiquidation,
                'inventory_behavior' => 'deduct_on_sale',
            ];

            if ($product) {
                $product->update($data);
                $this->updated++;
            } else {
                $product = Product::create($data);
                $this->created++;
            }

            if ($size || $color) {
                $this->syncVariant($product, $size, $color, $barcode, $sku, $price, $cost, $stock, $row);
            }
        }
    }

    protected function syncVariant(
        Product $product,
        ?string $size,
        ?string $color,
        ?string $barcode,
        ?string $sku,
        float $price,
        float $cost,
        float $stock,
        array $row,
    ): void {
        $variantSku = trim((string) ($row['sku_variante'] ?? $row['variant_sku'] ?? '')) ?: ($sku ?: $product->sku ?: 'COL');
        $variantBar = trim((string) ($row['barcode_variante'] ?? $row['variant_barcode'] ?? '')) ?: $barcode;

        $attributes = [];
        if ($size) $attributes['size'] = $size;
        if ($color) $attributes['color'] = $color;
        if (! empty($row['estilo'])) $attributes['style'] = trim((string) $row['estilo']);

        $key = collect($attributes)->map(fn ($v, $k) => $k.':'.(string) $v)->implode('|');
        $variant = $product->variants()->where(function ($q) use ($variantSku, $attributes) {
            $q->where('sku', $variantSku);
            if ($attributes !== []) {
                $q->orWhereJsonContains('attributes->size', $attributes['size'] ?? null)
                    ->orWhereJsonContains('attributes->color', $attributes['color'] ?? null);
            }
        })->first();

        if (! $variant) {
            $variant = new ProductVariant();
            $variant->product_id = $product->id;
            $variant->business_id = $product->business_id;
        }

        $variant->fill([
            'sku' => $variantSku,
            'barcode' => $variantBar,
            'attributes' => $attributes ?: null,
            'price' => $price,
            'cost' => $cost,
            'stock' => $stock,
            'is_active' => true,
        ]);
        $variant->save();
        $this->variants++;
    }

    protected function normalizeRow($row): array
    {
        $arr = $row instanceof Collection ? $row->toArray() : $row;
        $out = [];
        foreach ($arr as $key => $value) {
            $normalized = Str::slug((string) $key, '_');
            $out[$normalized] = $value;
        }

        $aliases = [
            'codigo_barras' => 'codigo_de_barras',
            'barcode' => 'codigo_de_barras',
            'precio' => 'precio',
            'cost' => 'costo',
            'inventory' => 'stock',
            'collection' => 'coleccion',
            'style' => 'estilo',
            'size' => 'talla',
            'colour' => 'color',
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
        if (is_string($value)) {
            $value = str_replace([',', ' '], ['.', ''], $value);
        }

        return round((float) $value, 3);
    }
}
