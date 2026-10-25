<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\PriceListResource\Pages;
use App\Models\PriceList;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PriceListResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = PriceList::class;
    protected static string $businessModule = 'price_lists';

    protected static bool $advancedInventoryOnly = true;
    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'edit_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationLabel = 'Listas de Precios';
    protected static ?string $modelLabel = 'Lista de precios';
    protected static ?string $pluralModelLabel = 'Listas de precios';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 10;
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos')->schema([
                Forms\Components\TextInput::make('code')->label('Código')->required()->maxLength(30),
                Forms\Components\TextInput::make('name')->label('Nombre')->required()->maxLength(100),
                Forms\Components\Select::make('customer_segment')->label('Segmento')->options([
                    'particular' => 'Particular',
                    'mayorista' => 'Mayorista',
                    'contratista' => 'Contratista / Profesional',
                ]),
                Forms\Components\Toggle::make('is_default')->label('Predeterminada'),
                Forms\Components\Toggle::make('is_active')->label('Activa')->default(true),
                Forms\Components\TextInput::make('global_markup_pct')->label('Markup % global')->numeric()->default(0),
                Forms\Components\TextInput::make('global_discount_pct')->label('Descuento % global')->numeric()->default(0),
            ])->columns(2),
            Forms\Components\Section::make('Precios por producto')->schema([
                Forms\Components\Repeater::make('items')->relationship('items')->schema([
                    Forms\Components\Select::make('product_id')->label('Producto')
                        ->options(fn () => Product::query()->orderBy('name')->limit(500)->pluck('name', 'id'))
                        ->searchable()->required(),
                    Forms\Components\TextInput::make('price')->label('Precio')->numeric()->required(),
                    Forms\Components\TextInput::make('min_quantity')->label('Cant. mínima')->numeric()->default(1),
                ])->columns(3)->defaultItems(0),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('name')->searchable(),
            Tables\Columns\TextColumn::make('customer_segment')->badge(),
            Tables\Columns\IconColumn::make('is_default')->boolean(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
            Tables\Columns\TextColumn::make('items_count')->counts('items')->label('Items'),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
            'create' => Pages\CreatePriceList::route('/create'),
            'edit' => Pages\EditPriceList::route('/{record}/edit'),
        ];
    }
}
