<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RetailVariantMatrix extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationLabel = 'Matriz de variantes';
    protected static ?string $title = 'Matriz de variantes';
    protected static ?string $navigationGroup = 'Tienda';
    protected static ?int $navigationSort = 24;
    protected static string $view = 'filament.pages.retail-variant-matrix';

    public ?array $formData = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Producto base')
                    ->options(fn () => Product::query()->where('business_id', filament()->getTenant()?->getKey())->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Forms\Components\Textarea::make('sizes')
                    ->label('Tallas (una por línea o separadas por coma)')
                    ->rows(4)
                    ->placeholder("S\nM\nL\nXL")
                    ->required(),
                Forms\Components\Textarea::make('colors')
                    ->label('Colores (uno por línea o separados por coma)')
                    ->rows(4)
                    ->placeholder("Negro\nBlanco\nAzul")
                    ->required(),
                Forms\Components\TextInput::make('stock')
                    ->label('Stock inicial por variante')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Forms\Components\TextInput::make('price')
                    ->label('Precio por variante (opcional)')
                    ->numeric()
                    ->prefix('RD$')
                    ->placeholder('Deja vacío para usar el precio base'),
            ])
            ->statePath('formData');
    }

    public function generateMatrix(): void
    {
        $data = $this->form->getState();
        $tenant = filament()->getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Business || ! $user instanceof User || ! $tenant->isRetailStore()) {
            Notification::make()->title('Sin acceso')->body('La matriz de variantes solo está disponible para tiendas/boutiques.')->danger()->send();

            return;
        }

        $product = Product::query()->find($data['product_id'] ?? null);
        if (! $product) {
            Notification::make()->title('Producto no encontrado')->body('Debes seleccionar un producto válido.')->danger()->send();

            return;
        }

        $sizes = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) ($data['sizes'] ?? '')))));
        $colors = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) ($data['colors'] ?? '')))));

        if ($sizes === [] || $colors === []) {
            Notification::make()->title('Datos incompletos')->body('Debes indicar al menos una talla y un color.')->warning()->send();

            return;
        }

        $generated = $product->generateVariantMatrix($sizes, $colors, [
            'stock' => (float) ($data['stock'] ?? 0),
            'price' => $data['price'] ?? $product->price,
            'cost' => $product->cost,
            'barcode_prefix' => $product->sku ?: $product->barcode ?: 'VAR',
        ]);

        Notification::make()
            ->title('Matriz generada')
            ->body("Se crearon {$generated} variantes para {$product->name}.")
            ->success()
            ->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = filament()->getTenant();
        $user = auth()->user();

        if (! $tenant instanceof Business || ! $user instanceof User) {
            return false;
        }

        return $tenant->isRetailStore() && $user->hasRoleInBusiness(['owner', 'admin', 'manager']);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }
}
