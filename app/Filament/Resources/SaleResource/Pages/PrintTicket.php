<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Actions;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;

class PrintTicket extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SaleResource::class;

    protected static string $view = 'filament.resources.sale-resource.pages.print-ticket';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->loadMissing(['items', 'customer', 'business']);
    }

    public function getTitle(): string
    {
        /** @var Sale $sale */
        $sale = $this->getRecord();

        return 'Ticket #'.($sale->sale_number ?? $sale->id);
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('imprimir')
                ->label('Imprimir')
                ->icon('heroicon-o-printer')
                ->color('primary')
                ->action(fn () => $this->js('window.print()')),

            Actions\Action::make('pdf')
                ->label('PDF 80mm')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn (): string => route('print.ticket', $this->getRecord()))
                ->openUrlInNewTab(),

            Actions\Action::make('volver')
                ->label('Volver a ventas')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SaleResource::getUrl('index')),
        ];
    }
}
