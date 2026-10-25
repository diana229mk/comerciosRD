<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\StockTransferResource\Pages;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\StockTransfer;
use App\Services\TransferService;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockTransferResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = StockTransfer::class;
    protected static string $businessModule = 'transfers';

    protected static bool $advancedInventoryOnly = true;
    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'edit_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-circle';
    protected static ?string $navigationLabel = 'Transferencias';
    protected static ?string $modelLabel = 'Transferencia';
    protected static ?string $pluralModelLabel = 'Transferencias';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 35;
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('Se genera automáticamente'),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    'borrador' => 'Borrador',
                    'enviada' => 'Enviada',
                    'recibida' => 'Recibida',
                    'cancelada' => 'Cancelada',
                ])
                ->default('borrador')
                ->disabled()
                ->dehydrated(false),
            Forms\Components\Select::make('from_location_id')
                ->label('Ubicación origen')
                ->options(fn () => ProductLocation::query()->pluck('code', 'id'))
                ->required()
                ->searchable()
                ->disabled(fn ($record) => $record && $record->status !== 'borrador'),
            Forms\Components\Select::make('to_location_id')
                ->label('Ubicación destino')
                ->options(fn () => ProductLocation::query()->pluck('code', 'id'))
                ->required()
                ->searchable()
                ->different('from_location_id')
                ->disabled(fn ($record) => $record && $record->status !== 'borrador'),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),

            Forms\Components\Repeater::make('items')
                ->relationship()
                ->label('Productos')
                ->schema([
                    Forms\Components\Select::make('product_id')
                        ->label('Producto')
                        ->options(fn () => Product::query()->where('is_active', true)->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Forms\Components\TextInput::make('quantity')
                        ->label('Cantidad')
                        ->numeric()
                        ->minValue(0.001)
                        ->required(),
                    Forms\Components\TextInput::make('received_quantity')
                        ->label('Recibido')
                        ->numeric()
                        ->default(0)
                        ->disabled(),
                    Forms\Components\TextInput::make('notes')->label('Notas'),
                ])
                ->columns(4)
                ->columnSpanFull()
                ->disabled(fn ($record) => $record && $record->status !== 'borrador'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Código')->searchable(),
            Tables\Columns\TextColumn::make('fromLocation.code')->label('Origen'),
            Tables\Columns\TextColumn::make('toLocation.code')->label('Destino'),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()->colors([
                'gray' => 'borrador',
                'warning' => 'enviada',
                'success' => 'recibida',
                'danger' => 'cancelada',
            ]),
            Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Ítems'),
            Tables\Columns\TextColumn::make('sent_at')->label('Enviada')->dateTime('d/m/Y H:i'),
            Tables\Columns\TextColumn::make('received_at')->label('Recibida')->dateTime('d/m/Y H:i'),
            Tables\Columns\TextColumn::make('created_at')->label('Creada')->dateTime('d/m/Y'),
        ])
        ->defaultSort('created_at', 'desc')
        ->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'borrador' => 'Borrador',
                'enviada' => 'Enviada',
                'recibida' => 'Recibida',
                'cancelada' => 'Cancelada',
            ]),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('send')
                ->label('Enviar')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (StockTransfer $r) => $r->status === 'borrador')
                ->action(function (StockTransfer $r) {
                    try {
                        app(TransferService::class)->send($r);
                        Notification::make()->title('Transferencia enviada')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
            Tables\Actions\Action::make('receive')
                ->label('Recibir')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (StockTransfer $r) => $r->status === 'enviada')
                ->action(function (StockTransfer $r) {
                    try {
                        app(TransferService::class)->receive($r);
                        Notification::make()->title('Transferencia recibida')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
            Tables\Actions\Action::make('cancel')
                ->label('Cancelar')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (StockTransfer $r) => in_array($r->status, ['borrador', 'enviada']))
                ->action(function (StockTransfer $r) {
                    try {
                        app(TransferService::class)->cancel($r);
                        Notification::make()->title('Cancelada')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Error')->body($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTransfers::route('/'),
            'create' => Pages\CreateStockTransfer::route('/create'),
            'edit' => Pages\EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
