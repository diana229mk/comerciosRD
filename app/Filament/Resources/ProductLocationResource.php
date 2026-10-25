<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ProductLocationResource\Pages;
use App\Models\ProductLocation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductLocationResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = ProductLocation::class;
    protected static string $businessModule = 'locations';

    protected static bool $advancedInventoryOnly = true;
    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'edit_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $navigationIcon = 'heroicon-o-map-pin';
    protected static ?string $navigationLabel = 'Ubicaciones';
    protected static ?string $modelLabel = 'Ubicación';
    protected static ?string $pluralModelLabel = 'Ubicaciones';
    protected static ?string $navigationGroup = 'Ferretería';
    protected static ?int $navigationSort = 30;
    protected static ?string $tenantOwnershipRelationshipName = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->label('Código')->required()->maxLength(30),
            Forms\Components\TextInput::make('aisle')->label('Pasillo')->maxLength(30),
            Forms\Components\TextInput::make('rack')->label('Estante')->maxLength(30),
            Forms\Components\TextInput::make('bin')->label('Bin')->maxLength(30),
            Forms\Components\TextInput::make('zone')->label('Zona')->maxLength(30),
            Forms\Components\Textarea::make('notes')->label('Notas')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->searchable(),
            Tables\Columns\TextColumn::make('aisle')->label('Pasillo'),
            Tables\Columns\TextColumn::make('rack')->label('Estante'),
            Tables\Columns\TextColumn::make('bin')->label('Bin'),
            Tables\Columns\TextColumn::make('zone')->label('Zona'),
            Tables\Columns\TextColumn::make('products_count')->counts('products')->label('Productos'),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductLocations::route('/'),
            'create' => Pages\CreateProductLocation::route('/create'),
            'edit' => Pages\EditProductLocation::route('/{record}/edit'),
        ];
    }
}
