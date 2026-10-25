<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Enums\PurchaseStatus;
use App\Filament\Resources\PurchaseResource;
use App\Services\PurchaseService;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recibir')
                ->label('Recibir compra')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->isDraft())
                ->action(function (): void {
                    try {
                        app(PurchaseService::class)->receive($this->record);
                        Notification::make()->title('Compra recibida')->success()->send();
                        $this->refreshFormData(['status', 'received_at', 'payable_id']);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\EditAction::make()
                ->visible(fn (): bool => ! $this->record->isReceived()),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Compra')
                    ->schema([
                        Infolists\Components\TextEntry::make('purchase_number')
                            ->label('Número'),
                        Infolists\Components\TextEntry::make('supplier_name')
                            ->label('Proveedor'),
                        Infolists\Components\TextEntry::make('purchase_date')
                            ->label('Fecha')
                            ->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('due_date')
                            ->label('Vencimiento')
                            ->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Estado')
                            ->badge(),
                        Infolists\Components\TextEntry::make('payment_status')
                            ->label('Pago')
                            ->badge(),
                        Infolists\Components\TextEntry::make('invoice_number')
                            ->label('Factura proveedor'),
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('tax')
                            ->label('Impuesto')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('total')
                            ->label('Total')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('amount_paid')
                            ->label('Pagado')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('received_at')
                            ->label('Recibida el')
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('notes')
                            ->label('Notas')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Infolists\Components\RepeatableEntry::make('items')
                    ->label('Productos')
                    ->schema([
                        Infolists\Components\TextEntry::make('product_name')
                            ->label('Producto'),
                        Infolists\Components\TextEntry::make('quantity')
                            ->label('Cant.'),
                        Infolists\Components\TextEntry::make('unit_cost')
                            ->label('Costo')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label('Subtotal')
                            ->money('DOP'),
                    ])
                    ->columns(4),
            ]);
    }
}
