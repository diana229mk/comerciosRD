<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ChecksBusinessModule;
use App\Filament\Resources\ProductSeasonResource\Pages;
use App\Models\ProductSeason;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductSeasonResource extends Resource
{
    use ChecksBusinessModule;

    protected static ?string $model = ProductSeason::class;
    protected static string $businessModule = 'seasons';

    protected static bool $retailOnly = true;
    protected static string $viewPermission = 'view_products';
    protected static string $createPermission = 'edit_products';
    protected static string $editPermission = 'edit_products';
    protected static ?string $navigationIcon = 'heroicon-o-calendar';
    protected static ?string $navigationLabel = 'Temporadas';
    protected static ?string $modelLabel = 'Temporada';
    protected static ?string $pluralModelLabel = 'Temporadas';
    protected static ?string $navigationGroup = 'Tienda';
    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nombre')->required()->maxLength(100),
            Forms\Components\TextInput::make('code')->label('Código')->required()->maxLength(30),
            Forms\Components\Select::make('type')->label('Tipo')->options([
                'season' => 'Temporada',
                'collection' => 'Colección',
                'clearance' => 'Liquidación',
            ])->default('season')->required(),
            Forms\Components\DatePicker::make('start_date')->label('Inicio'),
            Forms\Components\DatePicker::make('end_date')->label('Fin'),
            Forms\Components\Toggle::make('is_active')->label('Activa')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('start_date')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('end_date')->date('d/m/Y'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options([
                    'season' => 'Temporada',
                    'collection' => 'Colección',
                    'clearance' => 'Liquidación',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductSeasons::route('/'),
            'create' => Pages\CreateProductSeason::route('/create'),
            'edit' => Pages\EditProductSeason::route('/{record}/edit'),
        ];
    }
}
