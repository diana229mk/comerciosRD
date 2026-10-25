<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductKitItem;
use Illuminate\Support\Collection;

/**
 * Carta pública para el QR. Solo datos de menú (nombre, precio, categoría).
 * Nunca expone costo, stock ni otros negocios.
 */
class MenuCatalogService
{
    /**
     * @return array{
     *     business: Business,
     *     url: string,
     *     categories: Collection<int, array{id:?string, name:string, items: Collection}>
     * }
     */
    public function forBusiness(Business $business): array
    {
        $ingredientIds = ProductKitItem::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->pluck('component_product_id')
            ->filter()
            ->unique()
            ->values();

        $products = Product::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->active()
            ->where('price', '>', 0)
            ->when($ingredientIds->isNotEmpty(), function ($q) use ($ingredientIds): void {
                $q->where(function ($q2) use ($ingredientIds): void {
                    $q2->where('is_kit', true)
                        ->orWhereNotIn('id', $ingredientIds);
                });
            })
            ->with(['category:id,name,sort_order,is_active'])
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'price', 'image', 'category_id', 'is_kit', 'unit']);

        $grouped = $products->groupBy(fn (Product $p) => $p->category_id ?: '_none');

        $categoryRows = ProductCategory::withoutBusinessScope()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $categories = collect();
        foreach ($categoryRows as $cat) {
            $items = $grouped->get($cat->id, collect());
            if ($items->isEmpty()) {
                continue;
            }
            $categories->push([
                'id' => (string) $cat->id,
                'name' => $cat->name,
                'items' => $items->map(fn (Product $p) => $this->present($p))->values(),
            ]);
        }

        $uncat = $grouped->get('_none', collect());
        if ($uncat->isNotEmpty()) {
            $categories->push([
                'id' => null,
                'name' => 'Otros',
                'items' => $uncat->map(fn (Product $p) => $this->present($p))->values(),
            ]);
        }

        return [
            'business' => $business,
            'url' => $this->publicUrl($business),
            'categories' => $categories,
        ];
    }

    public function publicUrl(Business $business): string
    {
        return route('menu.public', ['slug' => $business->slug], absolute: true);
    }

    /**
     * @return array{id:string, name:string, description:?string, price:float, image:?string, unit:?string}
     */
    protected function present(Product $product): array
    {
        return [
            'id' => (string) $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (float) $product->price,
            'image' => $product->imageUrl(),
            'unit' => $product->unit,
        ];
    }
}
