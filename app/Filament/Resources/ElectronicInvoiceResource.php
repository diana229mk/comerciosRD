<?php

namespace App\Filament\Resources;

use App\Enums\EcfType;
use App\Enums\ElectronicInvoiceStatus;
use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ElectronicInvoiceResource\Pages;
use App\Models\ElectronicInvoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ElectronicInvoiceResource extends Resource
{
    use ChecksBusinessModule;

    protected static string $businessModule = 'electronic_invoicing';

    protected static ?string $model = ElectronicInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Comprobantes e-CF';

    protected static ?string $modelLabel = 'Comprobante Electrónico';

    protected static ?string $pluralModelLabel = 'Comprobantes Electrónicos';

    protected static ?string $navigationGroup = 'Facturación Electrónica';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        // Solo lectura: se emiten desde Ventas
        return $form
            ->schema([
                Forms\Components\Section::make('Comprobante')
                    ->schema([
                        Forms\Components\TextInput::make('ecf_number')
                            ->label('Número e-CF')
                            ->disabled(),
                        Forms\Components\Select::make('ecf_type')
                            ->label('Tipo')
                            ->options(EcfType::options())
                            ->disabled(),
                        Forms\Components\TextInput::make('status')
                            ->label('Estado')
                            ->disabled(),
                        Forms\Components\DatePicker::make('issue_date')
                            ->label('Fecha de emisión')
                            ->disabled(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Emisor')
                    ->schema([
                        Forms\Components\TextInput::make('issuer_rnc')->label('RNC')->disabled(),
                        Forms\Components\TextInput::make('issuer_name')->label('Nombre')->disabled(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Comprador')
                    ->schema([
                        Forms\Components\TextInput::make('buyer_rnc')->label('RNC / Cédula')->disabled(),
                        Forms\Components\TextInput::make('buyer_name')->label('Nombre')->disabled(),
                        Forms\Components\TextInput::make('buyer_email')->label('Email')->disabled(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Totales')
                    ->schema([
                        Forms\Components\TextInput::make('subtotal')->label('Subtotal')->prefix('RD$')->disabled(),
                        Forms\Components\TextInput::make('tax')->label('ITBIS')->prefix('RD$')->disabled(),
                        Forms\Components\TextInput::make('total')->label('Total')->prefix('RD$')->disabled(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Comprobante')
                    ->schema([
                        Infolists\Components\TextEntry::make('ecf_number')
                            ->label('Número e-CF')
                            ->weight('bold')
                            ->copyable(),
                        Infolists\Components\TextEntry::make('ecf_type')
                            ->label('Tipo')
                            ->formatStateUsing(fn ($state): string => $state instanceof EcfType
                                ? $state->value.' - '.$state->label()
                                : (string) $state)
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Estado')
                            ->formatStateUsing(fn ($state): string => $state instanceof ElectronicInvoiceStatus
                                ? $state->label()
                                : match ((string) $state) {
                                    'draft' => 'Borrador',
                                    'issued' => 'Emitido',
                                    'sent' => 'Enviado DGII',
                                    'accepted' => 'Aceptado',
                                    'rejected' => 'Rechazado',
                                    'cancelled' => 'Anulado',
                                    default => (string) $state,
                                })
                            ->badge()
                            ->color(fn ($state): string => $state instanceof ElectronicInvoiceStatus
                                ? $state->color()
                                : 'gray'),
                        Infolists\Components\TextEntry::make('issue_date')
                            ->label('Fecha de emisión')
                            ->date('d/m/Y'),
                        Infolists\Components\TextEntry::make('sale.sale_number')
                            ->label('Venta origen')
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Emisor')
                    ->schema([
                        Infolists\Components\TextEntry::make('issuer_rnc')->label('RNC'),
                        Infolists\Components\TextEntry::make('issuer_name')->label('Nombre'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Comprador')
                    ->schema([
                        Infolists\Components\TextEntry::make('buyer_rnc')->label('RNC / Cédula')->placeholder('—'),
                        Infolists\Components\TextEntry::make('buyer_name')->label('Nombre')->placeholder('Consumidor final'),
                        Infolists\Components\TextEntry::make('buyer_email')->label('Email')->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Totales')
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('DOP'),
                        Infolists\Components\TextEntry::make('discount')->label('Descuento')->money('DOP'),
                        Infolists\Components\TextEntry::make('tax')->label('ITBIS')->money('DOP'),
                        Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP')->weight('bold'),
                    ])
                    ->columns(4),

                Infolists\Components\RepeatableEntry::make('items')
                    ->label('Líneas')
                    ->schema([
                        Infolists\Components\TextEntry::make('product_name')->label('Producto'),
                        Infolists\Components\TextEntry::make('quantity')->label('Cant.'),
                        Infolists\Components\TextEntry::make('unit_price')->label('Precio')->money('DOP'),
                        Infolists\Components\TextEntry::make('tax_amount')->label('ITBIS')->money('DOP'),
                        Infolists\Components\TextEntry::make('total')->label('Total')->money('DOP'),
                    ])
                    ->columns(5),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ecf_number')
                    ->label('Número e-CF')
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('ecf_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state): string => $state instanceof EcfType
                        ? $state->value
                        : (string) $state)
                    ->badge(),

                Tables\Columns\TextColumn::make('buyer_name')
                    ->label('Cliente')
                    ->searchable()
                    ->placeholder('Consumidor final'),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('DOP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tax')
                    ->label('ITBIS')
                    ->money('DOP')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ElectronicInvoiceStatus
                        ? $state->label()
                        : match ((string) $state) {
                            'draft' => 'Borrador',
                            'issued' => 'Emitido',
                            'sent' => 'Enviado DGII',
                            'accepted' => 'Aceptado',
                            'rejected' => 'Rechazado',
                            'cancelled' => 'Anulado',
                            default => (string) $state,
                        })
                    ->color(fn ($state): string => $state instanceof ElectronicInvoiceStatus
                        ? $state->color()
                        : match ((string) $state) {
                            'draft', 'cancelled' => 'gray',
                            'issued' => 'info',
                            'sent' => 'warning',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('ecf_type')
                    ->options(EcfType::options()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(ElectronicInvoiceStatus::options()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('descargar_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('danger')
                    ->visible(fn (): bool => \App\Support\EcfFeature::enabled())
                    ->action(function (ElectronicInvoice $record) {
                        $pdf = \App\Services\EcfService::generatePdf($record);

                        return response()->streamDownload(function () use ($pdf): void {
                            echo $pdf->output();
                        }, $record->ecf_number.'.pdf', [
                            'Content-Type' => 'application/pdf',
                        ]);
                    }),

                Tables\Actions\Action::make('ver_xml')
                    ->label('XML')
                    ->icon('heroicon-o-code-bracket')
                    ->color('gray')
                    ->visible(fn (ElectronicInvoice $record): bool => \App\Support\EcfFeature::enabled()
                        && filled($record->xml_content))
                    ->action(function (ElectronicInvoice $record) {
                        return response()->streamDownload(function () use ($record): void {
                            echo $record->xml_content;
                        }, $record->ecf_number.'.xml', [
                            'Content-Type' => 'application/xml; charset=UTF-8',
                        ]);
                    }),

                Tables\Actions\Action::make('regenerar_xml')
                    ->label('Regenerar XML')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (): bool => \App\Support\EcfFeature::enabled())
                    ->requiresConfirmation()
                    ->modalHeading('Regenerar XML del e-CF')
                    ->modalDescription('Se volverá a generar el XML desde los datos del comprobante (sin firma digital).')
                    ->action(function (ElectronicInvoice $record): void {
                        try {
                            \App\Services\EcfService::regenerateXml($record);
                            \Filament\Notifications\Notification::make()
                                ->title('XML regenerado')
                                ->body($record->ecf_number)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('No se pudo regenerar el XML')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Sin comprobantes electrónicos')
            ->emptyStateDescription('Emite un e-CF desde una venta completada (botón “Emitir e-CF”).');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->with(['items', 'sale', 'customer']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListElectronicInvoices::route('/'),
            'view' => Pages\ViewElectronicInvoice::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        if (! static::tenantHasModule()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin', 'supervisor', 'cashier']) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        if (! \App\Support\EcfFeature::enabled()) {
            return false;
        }

        return auth()->user()?->hasRoleInBusiness(['owner', 'admin']) ?? false;
    }
}
