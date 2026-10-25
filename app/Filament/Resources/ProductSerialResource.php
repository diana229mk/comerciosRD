<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ProductSerialResource\Pages;
use App\Models\Product;
use App\Models\ProductSerial;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductSerialResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = ProductSerial::class;
    protected static string $businessModule = 'serials';

    protected static bool $advancedInventoryOnly = true;
    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'edit_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationLabel = 'Series / IMEI';
    protected static ?string $modelLabel = 'Serie';
    protected static ?string $pluralModelLabel = 'Series';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 40;
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')->label('Producto')
                ->options(fn () => Product::query()->where('tracks_serial', true)->orderBy('name')->pluck('name', 'id'))
                ->searchable()->required(),
            Forms\Components\TextInput::make('serial_number')->label('N° serie / IMEI')->required(),
            Forms\Components\Select::make('status')->options([
                'available' => 'Disponible', 'reserved' => 'Reservado',
                'sold' => 'Vendido', 'returned' => 'Devuelto', 'defective' => 'Defectuoso',
            ])->default('available')->required(),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('serial_number')->searchable()->label('Serie'),
            Tables\Columns\TextColumn::make('product.name')->label('Producto')->searchable(),
            Tables\Columns\TextColumn::make('status')->badge()->colors([
                'success' => 'available', 'warning' => 'reserved',
                'gray' => 'sold', 'primary' => 'returned', 'danger' => 'defective',
            ]),
            Tables\Columns\TextColumn::make('sale.sale_number')->label('Venta'),
            Tables\Columns\TextColumn::make('sold_at')->dateTime()->label('Vendido'),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'available' => 'Disponible', 'sold' => 'Vendido', 'defective' => 'Defectuoso',
            ]),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductSerials::route('/'),
            'create' => Pages\CreateProductSerial::route('/create'),
            'edit' => Pages\EditProductSerial::route('/{record}/edit'),
        ];
    }
}
