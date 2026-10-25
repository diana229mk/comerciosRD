<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\SpecialOrderResource\Pages;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SpecialOrder;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SpecialOrderResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = SpecialOrder::class;
    protected static string $businessModule = 'special_orders';

    protected static bool $hardwareOnly = true;
    protected static string $viewPermission = 'view_sales';
    protected static string $createPermission = 'create_sales';
    protected static string $editPermission = 'edit_sales';
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Pedidos Especiales';
    protected static ?string $modelLabel = 'Pedido Especial';
    protected static ?string $pluralModelLabel = 'Pedidos Especiales';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 40;
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Código')
                ->disabled()->dehydrated(false)
                ->placeholder('Se genera automáticamente'),
            Forms\Components\Select::make('status')
                ->label('Estado')
                ->options([
                    'solicitado' => 'Solicitado',
                    'cotizado' => 'Cotizado',
                    'ordenado' => 'Ordenado al proveedor',
                    'recibido' => 'Recibido',
                    'entregado' => 'Entregado al cliente',
                    'cancelado' => 'Cancelado',
                ])
                ->default('solicitado')
                ->required(),
            Forms\Components\Select::make('customer_id')
                ->label('Cliente')
                ->options(fn () => Customer::query()->pluck('name', 'id'))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('supplier_id')
                ->label('Proveedor (opcional)')
                ->options(fn () => Supplier::query()->pluck('name', 'id'))
                ->searchable(),
            Forms\Components\DatePicker::make('expected_date')->label('Fecha esperada'),
            Forms\Components\TextInput::make('deposit_amount')
                ->label('Depósito del cliente (RD$)')
                ->numeric()
                ->default(0),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),

            Forms\Components\Repeater::make('items')
                ->relationship()
                ->label('Ítems del pedido')
                ->schema([
                    Forms\Components\Select::make('product_id')
                        ->label('Producto (si existe)')
                        ->options(fn () => Product::query()->where('is_active', true)->pluck('name', 'id'))
                        ->searchable(),
                    Forms\Components\TextInput::make('description')
                        ->label('Descripción')
                        ->required()
                        ->helperText('Descripción libre — útil si el producto aún no existe en el catálogo.'),
                    Forms\Components\TextInput::make('quantity')
                        ->label('Cantidad')
                        ->numeric()->minValue(0.001)->required(),
                    Forms\Components\TextInput::make('quoted_cost')
                        ->label('Costo cotizado')->numeric(),
                    Forms\Components\TextInput::make('quoted_price')
                        ->label('Precio a cliente')->numeric(),
                    Forms\Components\Select::make('status')
                        ->label('Estado')
                        ->options([
                            'pendiente' => 'Pendiente',
                            'ordenado' => 'Ordenado',
                            'recibido' => 'Recibido',
                            'entregado' => 'Entregado',
                            'cancelado' => 'Cancelado',
                        ])->default('pendiente'),
                    Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Código')->searchable(),
            Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->searchable(),
            Tables\Columns\TextColumn::make('supplier.name')->label('Proveedor'),
            Tables\Columns\TextColumn::make('status')->label('Estado')->badge()->colors([
                'gray' => 'solicitado',
                'info' => 'cotizado',
                'warning' => 'ordenado',
                'success' => ['recibido', 'entregado'],
                'danger' => 'cancelado',
            ]),
            Tables\Columns\TextColumn::make('expected_date')->label('Esperado')->date('d/m/Y'),
            Tables\Columns\TextColumn::make('deposit_amount')->label('Depósito')->money('DOP'),
            Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Ítems'),
            Tables\Columns\TextColumn::make('created_at')->label('Creado')->dateTime('d/m/Y'),
        ])
        ->defaultSort('created_at', 'desc')
        ->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'solicitado' => 'Solicitado',
                'cotizado' => 'Cotizado',
                'ordenado' => 'Ordenado',
                'recibido' => 'Recibido',
                'entregado' => 'Entregado',
                'cancelado' => 'Cancelado',
            ]),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpecialOrders::route('/'),
            'create' => Pages\CreateSpecialOrder::route('/create'),
            'edit' => Pages\EditSpecialOrder::route('/{record}/edit'),
        ];
    }
}
