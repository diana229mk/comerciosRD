<?php

namespace App\Filament\Pages;

use App\Models\ProductBatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

class PrintBatchLabels extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-tag';
    protected static ?string $navigationLabel = 'Etiquetas de lotes';
    protected static ?string $title = 'Imprimir etiquetas de lotes';
    protected static ?string $navigationGroup = 'Farmacia';
    protected static ?int $navigationSort = 50;
    protected static string $view = 'filament.pages.print-batch-labels';

    public ?array $filters = ['product_id' => null, 'copies' => 1, 'only_active' => true];

    /** @var array<string, bool> */
    public array $selected = [];

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();
        return $tenant && method_exists($tenant, 'isPharmacy') && $tenant->isPharmacy();
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
            Forms\Components\Grid::make(3)->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Producto (opcional)')
                    ->options(fn () => \App\Models\Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                    ->searchable(),
                Forms\Components\TextInput::make('copies')
                    ->label('Copias por lote')->numeric()->minValue(1)->maxValue(50)->default(1),
                Forms\Components\Toggle::make('only_active')->label('Solo lotes con stock'),
            ]),
        ])->statePath('filters');
    }

    public function getBatches()
    {
        $q = ProductBatch::query()->with('product:id,name,sku,price');
        if ($this->filters['product_id'] ?? null) {
            $q->where('product_id', $this->filters['product_id']);
        }
        if ($this->filters['only_active'] ?? true) {
            $q->where('quantity', '>', 0);
        }
        return $q->orderBy('created_at', 'desc')->limit(200)->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Imprimir seleccionadas')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->url(fn () => route('print.batch-labels', [
                    'ids' => implode(',', collect($this->selected)->filter()->keys()->all()),
                    'copies' => (int) ($this->filters['copies'] ?? 1),
                ]))
                ->openUrlInNewTab(),
        ];
    }
}
