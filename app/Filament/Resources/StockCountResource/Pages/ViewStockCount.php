<?php

namespace App\Filament\Resources\StockCountResource\Pages;

use App\Filament\Resources\StockCountResource;
use App\Models\StockCount;
use App\Services\StockCountService;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    protected static string $view = 'filament.resources.stock-count.view';

    public array $counted = [];

    public function mount(int | string $record): void
    {
        parent::mount($record);

        /** @var StockCount $count */
        $count = $this->getRecord();
        $count->loadMissing(['items.product', 'items.batch']);

        foreach ($count->items as $item) {
            $this->counted[(string) $item->id] = $item->counted_quantity !== null
                ? (float) $item->counted_quantity
                : null;
        }
    }

    protected function getHeaderActions(): array
    {
        /** @var StockCount $count */
        $count = $this->getRecord();

        return [
            Actions\Action::make('save_counts')
                ->label('Guardar avance')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $count->isEditable())
                ->action(function () {
                    /** @var StockCount $count */
                    $count = $this->getRecord();
                    try {
                        app(StockCountService::class)->saveCount($count, $this->counted);
                        Notification::make()->title('Avance guardado')->success()->send();
                        $this->refreshFormData([]);
                        $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('complete')
                ->label('Completar y aplicar ajustes')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $count->isEditable())
                ->requiresConfirmation()
                ->modalDescription('Se generarán ajustes automáticos por cada diferencia y se actualizará el stock. Esta acción es irreversible.')
                ->action(function () {
                    /** @var StockCount $count */
                    $count = $this->getRecord();
                    try {
                        // Guarda antes de completar
                        app(StockCountService::class)->saveCount($count, $this->counted);
                        $count = app(StockCountService::class)->complete($count->fresh());
                        Notification::make()
                            ->title('Conteo completado')
                            ->body("Ajustes aplicados. Impacto: RD$ ".number_format((float) $count->total_cost_impact, 2))
                            ->success()->send();
                        $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
                    } catch (\Throwable $e) {
                        Notification::make()->title('No se pudo completar')->body($e->getMessage())->danger()->send();
                    }
                }),

            Actions\Action::make('cancel_count')
                ->label('Cancelar conteo')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $count->isEditable())
                ->requiresConfirmation()
                ->action(function () {
                    /** @var StockCount $count */
                    $count = $this->getRecord();
                    app(StockCountService::class)->cancel($count);
                    Notification::make()->title('Conteo cancelado')->warning()->send();
                    $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
                }),
        ];
    }
}
