<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Picqer\Barcode\BarcodeGeneratorSVG;

class PrintPriceLabels extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Etiquetas de precio';
    protected static ?string $title = 'Imprimir etiquetas de precio';
    protected static ?string $navigationGroup = 'Ferretería';

    public static function getNavigationGroup(): ?string
    {
        $tenant = Filament::getTenant();
        if ($tenant instanceof Business && $tenant->isRetailStore()) {
            return 'Tienda';
        }
        if ($tenant instanceof Business && $tenant->isAutoPartsStore()) {
            return 'Inventario';
        }

        return 'Ferretería';
    }
    protected static ?int $navigationSort = 60;
    protected static string $view = 'filament.pages.print-price-labels';
    protected static ?string $slug = 'etiquetas-precio';

    public ?array $filters = [
        'product_id' => null,
        'copies' => 1,
        'show_price' => true,
        'show_stock' => false,
        'only_in_stock' => true,
        'label_size' => '40x25',
    ];
    public array $selected = [];

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();
        if (! $tenant instanceof Business || ! $user instanceof User) return false;
        if (! $tenant->usesAdvancedInventory() || ! $tenant->hasModule('products')) return false;
        return $user->hasRoleInBusiness(['owner', 'admin', 'supervisor']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(4)->schema([
                Forms\Components\Select::make('product_id')->label('Filtrar por producto')
                    ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                    ->searchable()->live(),
                Forms\Components\Select::make('label_size')->label('Tamaño')->options([
                    '40x25' => '40 × 25 mm',
                    '50x30' => '50 × 30 mm',
                    '70x35' => '70 × 35 mm',
                ])->default('40x25')->live(),
                Forms\Components\TextInput::make('copies')->label('Copias por etiqueta')
                    ->numeric()->minValue(1)->maxValue(50)->default(1)->live(),
                Forms\Components\Toggle::make('show_price')->label('Mostrar precio')->default(true)->live(),
                Forms\Components\Toggle::make('show_stock')->label('Mostrar stock (50/70 mm)')->default(false)->live(),
                Forms\Components\Toggle::make('only_in_stock')->label('Solo con stock')->default(true)->live(),
            ]),
        ])->statePath('filters');
    }

    public function getPrintableItems(): array
    {
        $tenant = Filament::getTenant();
        $query = Product::query()->with('variants')->where('is_active', true);
        if (! empty($this->filters['product_id'])) {
            $query->whereKey($this->filters['product_id']);
        } else {
            $query->orderBy('name')->limit(200);
        }

        $products = $query->get();

        if ($tenant instanceof Business && $tenant->isRetailStore()) {
            $items = [];
            $onlyInStock = (bool) ($this->filters['only_in_stock'] ?? false);
            foreach ($products as $product) {
                $variants = $product->variants()->where('is_active', true)->orderBy('sku')->get();
                if ($variants->isEmpty()) {
                    $productStock = (float) ($product->stock ?? 0);
                    if ($onlyInStock && $productStock <= 0) {
                        continue;
                    }
                    $items[] = [
                        'id' => (string) $product->id,
                        'product_id' => (string) $product->id,
                        'variant_id' => null,
                        'name' => $product->name,
                        'display_name' => $product->name,
                        'variant_label' => null,
                        'sku' => $product->sku,
                        'barcode' => $product->barcode,
                        'price' => (float) ($product->price ?? 0),
                        'stock' => $productStock,
                    ];
                    continue;
                }

                foreach ($variants as $variant) {
                    $variantStock = (float) ($variant->stock ?? $product->stock ?? 0);
                    if ($onlyInStock && $variantStock <= 0) {
                        continue;
                    }
                    $attributes = $variant->attributes ?? [];
                    $label = collect($attributes)->map(fn ($value, $key) => ucfirst($key).': '.(string) $value)->implode(' · ');
                    $items[] = [
                        'id' => (string) $variant->id,
                        'product_id' => (string) $product->id,
                        'variant_id' => (string) $variant->id,
                        'name' => $product->name,
                        'display_name' => $product->name.' '.($label !== '' ? '· '.$label : ''),
                        'variant_label' => $label !== '' ? $label : null,
                        'sku' => $variant->sku ?: $product->sku,
                        'barcode' => $variant->barcode ?: $product->barcode,
                        'price' => (float) ($variant->price ?? $product->price ?? 0),
                        'stock' => $variantStock,
                    ];
                }
            }

            return $items;
        }

        $onlyInStock = (bool) ($this->filters['only_in_stock'] ?? false);

        return $products
            ->map(fn (Product $product) => [
                'id' => (string) $product->id,
                'product_id' => (string) $product->id,
                'variant_id' => null,
                'name' => $product->name,
                'display_name' => $product->name,
                'variant_label' => null,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'price' => (float) ($product->price ?? 0),
                'stock' => (float) ($product->stock ?? 0),
            ])
            ->filter(fn (array $item) => ! $onlyInStock || (float) $item['stock'] > 0)
            ->values()
            ->all();
    }

    public function renderBarcode(string $code): string
    {
        try {
            $gen = new BarcodeGeneratorSVG();
            return $gen->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, 1.5, 40);
        } catch (\Throwable) {
            return '';
        }
    }

    public function getSelectedPrintableItems(): array
    {
        $selectedIds = array_keys(array_filter($this->selected));
        if ($selectedIds === []) {
            return [];
        }

        $items = $this->getPrintableItems();
        $byId = collect($items)->keyBy('id');

        return collect($selectedIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->values()
            ->all();
    }

    public function printUrl(): ?string
    {
        $ids = array_keys(array_filter($this->selected));
        if ($ids === []) {
            return null;
        }

        return route('print.price-labels', [
            'ids' => implode(',', $ids),
            'copies' => max(1, (int) ($this->filters['copies'] ?? 1)),
            'size' => $this->filters['label_size'] ?? '40x25',
            'show_price' => ($this->filters['show_price'] ?? true) ? 1 : 0,
            'show_stock' => ($this->filters['show_stock'] ?? false) ? 1 : 0,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_selected')
                ->label('Imprimir seleccionadas')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->url(fn (): string => $this->printUrl() ?: '#')
                ->openUrlInNewTab()
                ->disabled(fn (): bool => $this->printUrl() === null),
        ];
    }
}
