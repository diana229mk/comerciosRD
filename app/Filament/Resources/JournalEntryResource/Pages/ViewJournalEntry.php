<?php

namespace App\Filament\Resources\JournalEntryResource\Pages;

use App\Filament\Resources\JournalEntryResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewJournalEntry extends ViewRecord
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->visible(fn (): bool => $this->record->isDraft()),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Asiento')
                    ->schema([
                        Infolists\Components\TextEntry::make('entry_number')
                            ->label('Número'),
                        Infolists\Components\TextEntry::make('date')
                            ->label('Fecha')
                            ->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'publicado' ? 'Publicado' : 'Borrador')
                            ->color(fn (string $state): string => $state === 'publicado' ? 'success' : 'gray'),
                        Infolists\Components\TextEntry::make('reference')
                            ->label('Referencia')
                            ->placeholder('—'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('total_debit')
                            ->label('Total Débito')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('total_credit')
                            ->label('Total Crédito')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('creator.name')
                            ->label('Creado por')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\RepeatableEntry::make('lines')
                    ->label('Líneas')
                    ->schema([
                        Infolists\Components\TextEntry::make('account.code')
                            ->label('Código'),
                        Infolists\Components\TextEntry::make('account.name')
                            ->label('Cuenta'),
                        Infolists\Components\TextEntry::make('debit')
                            ->label('Débito')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('credit')
                            ->label('Crédito')
                            ->money('DOP'),
                        Infolists\Components\TextEntry::make('description')
                            ->label('Detalle')
                            ->placeholder('—'),
                    ])
                    ->columns(5),
            ]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load(['lines.account', 'creator']);

        return $data;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record->load(['lines.account', 'creator']);
    }
}
