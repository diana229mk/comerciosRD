<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Picqer\Barcode\BarcodeGeneratorSVG;

class PriceLabelController extends Controller
{
    public function print(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids', '')))
            ->filter()
            ->values()
            ->all();
        $copies = max(1, min(50, (int) $request->query('copies', 1)));
        $size = (string) $request->query('size', '40x25');
        if (! preg_match('/^\d{2}x\d{2}$/', $size)) {
            $size = '40x25';
        }
        [$w, $h] = array_map('intval', explode('x', $size));
        $showPrice = $request->boolean('show_price', true);
        $showStock = $request->boolean('show_stock', false) && $h >= 30;

        $gen = new BarcodeGeneratorSVG();
        $labels = [];

        foreach ($ids as $id) {
            $item = $this->resolveItem((string) $id);
            if ($item === null) {
                continue;
            }
            $code = $item['barcode'] ?: $item['sku'] ?: (string) $item['id'];
            try {
                $svg = $gen->getBarcode($code, $gen::TYPE_CODE_128, 1.2, 28);
            } catch (\Throwable) {
                $svg = '<svg viewBox="0 0 120 28" width="120" height="28"><text x="4" y="18" font-size="10">'.e($code).'</text></svg>';
            }
            $stockText = $showStock ? 'Stock '.number_format((float) $item['stock'], 0) : null;
            for ($i = 0; $i < $copies; $i++) {
                $labels[] = [
                    'name' => $item['name'],
                    'code' => $code,
                    'price' => (float) $item['price'],
                    'show_price' => $showPrice,
                    'stock_text' => $stockText,
                    'barcode_svg' => $svg,
                ];
            }
        }

        return view('print.price-labels', [
            'labels' => $labels,
            'w' => $w,
            'h' => $h,
        ]);
    }

    /**
     * @return array{id: string, name: string, sku: ?string, barcode: ?string, price: float, stock: float}|null
     */
    protected function resolveItem(string $id): ?array
    {
        $variant = ProductVariant::query()->with('product:id,name,sku,barcode,price,stock')->find($id);
        if ($variant) {
            $product = $variant->product;

            return [
                'id' => (string) $variant->id,
                'name' => trim(($product?->name ?? '').' '.$this->variantLabel($variant)),
                'sku' => $variant->sku ?: $product?->sku,
                'barcode' => $variant->barcode ?: $product?->barcode,
                'price' => (float) ($variant->price ?? $product?->price ?? 0),
                'stock' => (float) ($variant->stock ?? $product?->stock ?? 0),
            ];
        }

        $product = Product::query()->find($id);
        if (! $product) {
            return null;
        }

        return [
            'id' => (string) $product->id,
            'name' => (string) $product->name,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'price' => (float) ($product->price ?? 0),
            'stock' => (float) ($product->stock ?? 0),
        ];
    }

    protected function variantLabel(ProductVariant $variant): string
    {
        $attributes = $variant->getAttribute('attributes') ?? [];
        if (! is_array($attributes) || $attributes === []) {
            return '';
        }

        return collect($attributes)->map(fn ($value) => (string) $value)->implode(' ');
    }
}
