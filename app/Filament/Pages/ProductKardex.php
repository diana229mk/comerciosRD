<?php

namespace App\Filament\Pages;

use App\Models\InventoryMovement;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class ProductKardex extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Kardex';
    protected static ?string $title = 'Kardex de Producto';
    protected static ?string $navigationGroup = 'Inventario';
    protected static ?int $navigationSort = 25;
    protected static string $view = 'filament.pages.product-kardex';

    public ?array $filters = ['product_id' => null, 'from' => null, 'to' => null];

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        $role = auth()->user()?->getRoleInCurrentBusiness();
        return $tenant && in_array($role, ['owner', 'admin', 'supervisor'], true);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subDays(60)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Producto')
                    ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                    ->searchable()->required(),
                Forms\Components\DatePicker::make('from')->label('Desde')->required(),
                Forms\Components\DatePicker::make('to')->label('Hasta')->required(),
            ]),
        ])->statePath('filters');
    }

    public function getMovements(): array
    {
        $pid = $this->filters['product_id'] ?? null;
        if (! $pid) {
            return ['product' => null, 'rows' => collect(), 'opening' => 0, 'closing' => 0];
        }
        $product = Product::query()->find($pid);
        if (! $product) {
            return ['product' => null, 'rows' => collect(), 'opening' => 0, 'closing' => 0];
        }
        $from = $this->filters['from'] ?? now()->subDays(60)->toDateString();
        $to = $this->filters['to'] ?? now()->toDateString();

        // Saldo inicial: movimientos antes de $from
        $prior = InventoryMovement::query()
            ->where('product_id', $product->id)
            ->where('created_at', '<', $from.' 00:00:00')
            ->get();
        $opening = 0.0;
        foreach ($prior as $m) {
            $type = $m->type instanceof \App\Enums\InventoryMovementType
                ? $m->type
                : \App\Enums\InventoryMovementType::tryFrom((string) $m->type);
            $opening += ($type?->stockSign() ?? ($m->isEntry() ? 1 : -1)) * (float) $m->quantity;
        }

        $movs = InventoryMovement::query()
            ->with('batch:id,code')
            ->where('product_id', $product->id)
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $rows = $movs->map(function (InventoryMovement $m) use (&$running) {
            $type = $m->type instanceof \App\Enums\InventoryMovementType
                ? $m->type
                : \App\Enums\InventoryMovementType::tryFrom((string) $m->type);
            $sign = $type?->stockSign() ?? ($m->isEntry() ? 1 : -1);
            $qty = (float) $m->quantity;
            $running = round($running + ($sign * $qty), 3);

            return [
                'date' => $m->created_at,
                'type' => $type?->label() ?? (string) $m->type,
                'batch' => $m->batch?->code,
                'in' => $sign > 0 ? $qty : 0,
                'out' => $sign < 0 ? $qty : 0,
                'balance' => $running,
                'cost' => (float) $m->unit_cost,
                'reason' => $m->reason,
            ];
        });

        return [
            'product' => $product,
            'rows' => $rows,
            'opening' => $opening,
            'closing' => $running,
        ];
    }
}
