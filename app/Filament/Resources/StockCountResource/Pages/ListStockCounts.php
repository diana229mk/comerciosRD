<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockCount;
use App\Services\StockCountService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    protected function getTableQuery(): ?\Illuminate\Database\Eloquent\Builder
    {
        return StockCount::query()->orderByDesc('created_at');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_count')
                ->label('Nuevo conteo')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('scope')
                        ->label('Alcance')
                        ->options([
                            StockCount::SCOPE_FULL => 'Todo el catálogo',
                            StockCount::SCOPE_CATEGORY => 'Por categoría',
                            StockCount::SCOPE_SELECTION => 'Productos seleccionados',
                        ])
                        ->default(StockCount::SCOPE_FULL)
                        ->reactive()
                        ->required(),
                    Forms\Components\Select::make('category_ids')
                        ->label('Categorías')
                        ->multiple()
                        ->options(fn () => ProductCategory::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Forms\Get $get) => $get('scope') === StockCount::SCOPE_CATEGORY),
                    Forms\Components\Select::make('product_ids')
                        ->label('Productos')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                        ->visible(fn (Forms\Get $get) => $get('scope') === StockCount::SCOPE_SELECTION),
                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
                ])
                ->action(function (array $data): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    try {
                        $count = app(StockCountService::class)->open($tenant->id, [
                            'scope' => $data['scope'],
                            'scope_filters' => [
                                'category_ids' => $data['category_ids'] ?? [],
                                'product_ids' => $data['product_ids'] ?? [],
                            ],
                            'notes' => $data['notes'] ?? null,
                        ]);
                        Notification::make()
                            ->title("Conteo {$count->code} abierto")
                            ->body("{$count->total_items} ítems para contar.")
                            ->success()->send();
                        $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('No se pudo abrir el conteo')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),
        ];
    }
}
