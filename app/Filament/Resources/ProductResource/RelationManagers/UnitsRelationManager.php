<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\Business;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class UnitsRelationManager extends RelationManager
{
    use Concerns\LoadsEagerly;

    protected static string $relationship = 'units';
    protected static ?string $title = 'Unidades múltiples';
    protected static ?string $icon = 'heroicon-o-scale';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        $t = filament()->getTenant();
        return $t instanceof Business && $t->usesAdvancedInventory();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('unit_code')->label('Código')->required()->maxLength(20)
                ->helperText('Ej: un, caja, m, kg, saco'),
            Forms\Components\TextInput::make('label')->label('Etiqueta')->required()->maxLength(60),
            Forms\Components\TextInput::make('factor')->numeric()->required()->default(1)
                ->helperText('Cuántas unidades base contiene (ej: caja de 100 → 100)'),
            Forms\Components\TextInput::make('price')->numeric()->required(),
            Forms\Components\TextInput::make('barcode')->maxLength(60),
            Forms\Components\Toggle::make('is_base')->label('Es unidad base'),
            Forms\Components\Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('unit_code')->label('Código'),
            Tables\Columns\TextColumn::make('label'),
            Tables\Columns\TextColumn::make('factor')->numeric(3),
            Tables\Columns\TextColumn::make('price')->money('DOP'),
            Tables\Columns\IconColumn::make('is_base')->boolean(),
            Tables\Columns\IconColumn::make('is_active')->boolean(),
        ])->headerActions([Tables\Actions\CreateAction::make()])
        ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
