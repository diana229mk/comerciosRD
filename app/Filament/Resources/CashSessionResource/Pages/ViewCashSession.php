<?php

namespace App\Filament\Resources\CashSessionResource\Pages;

use App\Filament\Resources\CashSessionResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewCashSession extends ViewRecord
{
    protected static string $resource = CashSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => $this->record->isOpen()),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Sesión de caja')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'Abierta' : 'Cerrada')
                            ->color(fn (string $state): string => $state === 'open' ? 'success' : 'gray'),
                        Infolists\Components\TextEntry::make('user.name')
                            ->label('Abierta por'),
                        Infolists\Components\TextEntry::make('opened_at')
                            ->label('Apertura')
                            ->dateTime('d/m/Y H:i'),
                        Infolists\Components\TextEntry::make('closedByUser.name')
                            ->label('Cerrada por')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('closed_at')
                            ->label('Cierre')
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('opening_amount')
                            ->label('Monto inicial')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('total_sales_cash')
                            ->label('Ventas en efectivo')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('total_expenses_cash')
                            ->label('Gastos en efectivo')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('expected_amount')
                            ->label('Esperado')
                            ->money('DOP')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('closing_amount')
                            ->label('Contado')
                            ->money('DOP')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('difference')
                            ->label('Diferencia')
                            ->money('DOP')
                            ->placeholder('—')
                            ->color(fn ($state): string => match (true) {
                                $state === null => 'gray',
                                (float) $state > 0 => 'success',
                                (float) $state < 0 => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('opening_notes')
                            ->label('Notas apertura')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('closing_notes')
                            ->label('Notas cierre')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }
}
